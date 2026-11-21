<?php

namespace SaasPaws\Deploy\Ssh;

use DotDi\Interfaces\IDisposable;
use Swoole\Coroutine;
use Throwable;

/**
 * Coroutine SSH client — connect, run commands with real exit codes, drive an interactive
 * shell, and push files over SFTP.
 *
 * Requires Swoole >= 6.2 built with `--with-swoole-ssh2`: the ssh2_* functions are then
 * coroutine-hooked, so a long remote command yields the worker instead of blocking it (a
 * three-minute deploy would otherwise stall a two-worker site). It is API-compatible with PECL
 * ext-ssh2, which cannot be loaded at the same time.
 *
 * TWO MODES, and the choice matters:
 *
 *  - `exec()` — one channel per command. Gives a real `exit_status`, keeps stderr separate, and
 *    cannot be confused by shell echo. **Use this unless the command asks questions.**
 *  - `openShell()` + `execute()` — one persistent login shell for commands that prompt
 *    (git credentials, `composer` confirmations, a sudo password). Completion is detected with
 *    a random marker echoed back, and the marker carries `$?` so shell mode still yields exit
 *    codes. Prompts are answered by keyword match.
 *
 * Shell-mode specifics, each learned the hard way against the coroutine client:
 *  - `stream_get_contents()` waits for an EOF an interactive shell never sends — it hangs.
 *    Read with `fread()`.
 *  - Do NOT drain in an inner loop: the stream is blocking (and `stream_set_blocking(false)`
 *    does not take), so the read *after* the marker waits for data that never comes and every
 *    command costs ~64s. One read per iteration, then inspect the buffer.
 *  - `stream_set_timeout()` is what makes the deadline enforceable.
 *  - A bare `exit <n>` terminates the session; `execute()` subshells commands so a failing one
 *    reports a code instead of logging the shell out.
 *
 * Secrets registered with `addSecret()` are masked in everything this class emits — streamed
 * output, the transcript, and exception messages — so a token echoed by a failing git command
 * cannot reach a log or a database row.
 *
 * TIMEOUTS ARE ENFORCED ON THE REMOTE SIDE, with coreutils `timeout`, and that is deliberate.
 * Reads block until data arrives no matter what `stream_set_blocking`/`stream_set_timeout` are
 * set to, so a local deadline can only be tested BETWEEN reads — useless against a command that
 * goes silent forever (a build waiting on a prompt nobody answered). Cancelling from a watchdog
 * coroutine is not an option either: closing the channel or the session under a pending read
 * takes the process down. Wrapping the remote command means the far end kills it, the channel
 * reaches EOF, and the read returns on its own — reported here as exit 124. The local deadline
 * stays as a backstop for a box without coreutils.
 */
class SshService implements IDisposable
{
    private const READ_CHUNK = 8192;

    private ?SshCredentials $credentials = null;
    private $connection = null;
    private $shell = null;
    private ?string $privateKeyFile = null;
    private ?string $publicKeyFile = null;

    private string $transcript = '';
    private string $buffer = '';
    private string $marker = '';
    private string $markerCommand = '';
    private bool $disposed = false;

    /** @var callable|null Streams every chunk of output as it arrives (already scrubbed). */
    private $onOutput = null;

    /** @var string[] Literal values to mask everywhere. */
    private array $secrets = [];

    /** Seconds a single command may take before it is abandoned. */
    public int $commandTimeout = 900;

    /** Seconds to wait for the TCP connect + handshake. */
    public int $connectTimeout = 15;

    public function __construct(private ?string $tempDir = null)
    {
        $this->tempDir = $tempDir ?: sys_get_temp_dir();
    }

    // ---------------------------------------------------------------- connection

    /**
     * @param callable|null $onOutput fn(string $chunk): void — live output, already scrubbed.
     */
    public function connect(SshCredentials $credentials, ?callable $onOutput = null): void
    {
        $this->credentials = $credentials;
        $this->onOutput = $onOutput;

        if ($credentials->privateKey === null || $credentials->publicKey === null) {
            throw new SshException('SSH key pair is required (both private and public halves).');
        }
        // Both halves are needed: libssh2 derives the auth request from the public key and signs
        // with the private one.
        $this->privateKeyFile = $this->writeKeyFile('id', $credentials->privateKey);
        $this->publicKeyFile = $this->writeKeyFile('id_pub', $credentials->publicKey);

        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $this->connectTimeout);
        try {
            $connection = @ssh2_connect($credentials->host, $credentials->port);
        } finally {
            ini_set('default_socket_timeout', $previous);
        }
        if (!$connection) {
            throw new SshException("Can't reach {$credentials->describe()} over SSH.");
        }

        $authed = @ssh2_auth_pubkey_file(
            $connection,
            $credentials->userName,
            $this->publicKeyFile,
            $this->privateKeyFile,
            $credentials->passphrase !== null && $credentials->passphrase !== '' ? $credentials->passphrase : null
        );
        if (!$authed) {
            throw new SshException("SSH key authentication failed for {$credentials->describe()}.");
        }

        $this->connection = $connection;
        $this->log("Connected to {$credentials->describe()}\n");
    }

    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    // ---------------------------------------------------------------- exec mode

    /**
     * Run one command on its own channel and return stdout/stderr/exit code.
     *
     * @param bool $enforce throw when the command exits non-zero (the deploy default — a failed
     *                      build must never let the pipeline reach the swap step).
     */
    public function exec(string $command, bool $enforce = true, ?int $timeout = null, ?string $stdin = null): SshResult
    {
        $this->requireConnection();
        $seconds = $timeout ?? $this->commandTimeout;
        $started = microtime(true);
        $this->log('$ ' . $this->scrub($command) . "\n");

        $stream = @ssh2_exec($this->connection, $this->bound($command, $seconds));
        if (!$stream) {
            throw new SshException('Failed to open an exec channel for: ' . $this->scrub($command));
        }
        if ($stdin !== null) {
            // The channel's stdin is writable, which is what lets `sudo -S` read a password
            // without it ever appearing in argv. EOF after it, or a command that reads until
            // EOF would hang.
            //
            // Written in a LOOP: fwrite on a channel returns how much it accepted, which for
            // anything past a password is not necessarily everything. A single unchecked fwrite is
            // how a file written this way ends up truncated with no error anywhere.
            $offset = 0;
            $length = strlen($stdin);
            $stalled = 0;
            while ($offset < $length) {
                $chunk = @fwrite($stream, substr($stdin, $offset, 8192));
                if ($chunk === false) {
                    @fclose($stream);
                    throw new SshException('Failed writing stdin for: ' . $this->scrub($command));
                }
                if ($chunk === 0) {
                    // The far side has not drained the window yet. Yield rather than spin, and give
                    // up eventually instead of hanging forever.
                    if (++$stalled > 1000) {
                        @fclose($stream);
                        throw new SshException(
                            "Stalled after $offset of $length stdin bytes for: " . $this->scrub($command)
                        );
                    }
                    Coroutine::sleep(0.01);
                    continue;
                }
                $stalled = 0;
                $offset += $chunk;
            }
            if (function_exists('ssh2_send_eof')) {
                @ssh2_send_eof($stream);
            }
        }
        $stderrStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
        stream_set_blocking($stream, true);
        stream_set_blocking($stderrStream, true);

        // Backstop only — see the class docblock. `timeout` on the far side is what actually
        // bounds a silent command; this catches a box that has no coreutils. The grace is
        // generous because a read can sit inside fread past the deadline.
        $deadline = microtime(true) + $seconds + 30;
        $out = '';
        $err = '';
        // Read both channels until each hits EOF, streaming as we go so a long build is visible
        // while it runs rather than only at the end.
        while (!feof($stream) || !feof($stderrStream)) {
            if (microtime(true) > $deadline) {
                @fclose($stderrStream);
                @fclose($stream);
                throw new SshException(
                    "Timed out after {$seconds}s (local backstop; is coreutils `timeout` installed "
                        . "on {$this->credentials->host}?): " . $this->scrub($command)
                );
            }
            $chunk = feof($stream) ? '' : (string) fread($stream, self::READ_CHUNK);
            if ($chunk !== '') {
                $out .= $chunk;
                $this->log($chunk);
            }
            $errChunk = feof($stderrStream) ? '' : (string) fread($stderrStream, self::READ_CHUNK);
            if ($errChunk !== '') {
                $err .= $errChunk;
                $this->log($errChunk);
            }
            if ($chunk === '' && $errChunk === '') {
                Coroutine::sleep(0.01);
            }
        }

        // Only final once the channel has drained — reading it earlier reports 0 for a command
        // that is still running.
        $meta = stream_get_meta_data($stream);
        $exitCode = isset($meta['exit_status']) ? (int) $meta['exit_status'] : null;
        @fclose($stderrStream);
        @fclose($stream);

        $result = new SshResult(
            $this->scrub($command),
            $this->scrub($out),
            $this->scrub($err),
            $exitCode,
            (microtime(true) - $started) * 1000
        );
        // 124 is coreutils `timeout` reporting that it killed the command. Always an error,
        // even when the caller did not want non-zero exits enforced — a half-run command has
        // left the box in an unknown state and continuing on top of that is worse than stopping.
        if ($exitCode === 124) {
            throw new SshException(
                "Timed out after {$seconds}s: {$result->command}",
                $result
            );
        }
        if ($enforce && !$result->ok()) {
            throw new SshException(
                "Command failed (exit {$exitCode}): {$result->command}\n" . $result->text(),
                $result
            );
        }
        return $result;
    }

    /**
     * Run a command as root.
     *
     * Three cases, decided by what the credentials carry: a root login runs it as-is; a sudo
     * password is piped to `sudo -S` on stdin; otherwise plain `sudo -n`, which FAILS rather
     * than waits when the box would have prompted — a deploy has nobody to answer a prompt, so
     * hanging forever is the worse outcome.
     */
    public function sudo(string $command, bool $enforce = true, ?int $timeout = null): SshResult
    {
        if ($this->credentials !== null && $this->credentials->userName === 'root') {
            return $this->exec($command, $enforce, $timeout);
        }
        $password = $this->credentials?->sudoPassword;
        if ($password !== null && $password !== '') {
            // -S reads the password from stdin, -p '' suppresses the prompt text so it cannot
            // be mistaken for command output.
            return $this->exec("sudo -S -p '' " . $command, $enforce, $timeout, $password . "\n");
        }
        return $this->exec('sudo -n ' . $command, $enforce, $timeout);
    }

    /** True when a privileged command can run at all (root, a password, or NOPASSWD sudo). */
    public function canSudo(): bool
    {
        if ($this->credentials !== null && $this->credentials->userName === 'root') {
            return true;
        }
        if (($this->credentials?->sudoPassword ?? '') !== '') {
            return true;
        }
        return $this->test('sudo -n true', 15);
    }

    /**
     * Wrap a command so the REMOTE side enforces the time limit (see the class docblock).
     * `-k 5` follows SIGTERM with SIGKILL five seconds later, so a process that ignores TERM
     * still dies and the channel still closes.
     */
    private function bound(string $command, int $seconds): string
    {
        return 'command -v timeout >/dev/null 2>&1 && exec timeout -k 5 ' . $seconds
            . ' sh -c ' . escapeshellarg($command)
            . ' || exec sh -c ' . escapeshellarg($command);
    }

    /** Convenience: run a command and return trimmed stdout, throwing on failure. */
    public function read(string $command, ?int $timeout = null): string
    {
        return $this->exec($command, true, $timeout)->text();
    }

    /** True when the command exits 0. Never throws on a non-zero exit — for probing. */
    public function test(string $command, ?int $timeout = null): bool
    {
        return $this->exec($command, false, $timeout)->ok();
    }

    // ---------------------------------------------------------------- shell mode

    /**
     * Open a persistent login shell. Only needed for commands that ask questions — everything
     * else should go through exec(), which is simpler and cannot be fooled by shell echo.
     */
    public function openShell(): void
    {
        $this->requireConnection();
        $this->marker = bin2hex(random_bytes(8));
        // base64 so the shell's echo of the command we typed cannot itself contain the marker
        // and end the wait before the command has run. $? rides along, giving shell mode the
        // exit codes it otherwise lacks.
        $encoded = base64_encode($this->marker);
        $this->markerCommand = 'echo "RC:$?:$(echo ' . escapeshellarg($encoded) . ' | base64 --decode)";';

        $shell = @ssh2_shell($this->connection, 'xterm');
        if (!$shell) {
            throw new SshException('Failed to open an interactive shell.');
        }
        // See the class docblock: blocking stream, one fread per tick, timeout for the deadline.
        stream_set_timeout($shell, 1);
        $this->shell = $shell;

        fwrite($this->shell, $this->markerCommand . "\n");
        $this->await();
    }

    /**
     * Run a command in the interactive shell, answering prompts by keyword.
     *
     * @param array $prompts [['keywords' => ['Username', 'github.com'], 'input' => '...'], ...]
     *                       Every keyword must appear before the answer is sent; each entry is
     *                       used at most once.
     * @return int the command's exit status
     */
    public function execute(string $command, array $prompts = [], ?int $timeout = null): int
    {
        if ($this->shell === null) {
            $this->openShell();
        }
        $this->log('$ ' . $this->scrub($command) . "\n");
        // Subshell: a bare `exit`/`logout` inside $command would otherwise end the session and
        // the marker would never come back. The remote `timeout` bounds it for the same reason
        // exec() needs one — a command that stops talking would otherwise strand this read.
        // Prompts still work: `timeout` passes stdin straight through.
        fwrite($this->shell, '( ' . $this->bound($command, $timeout ?? $this->commandTimeout) . ' );'
            . $this->markerCommand . "\n");
        return $this->await($prompts, $timeout);
    }

    /** Wait for the marker, answering prompts on the way. Returns the exit status it carries. */
    private function await(array $prompts = [], ?int $timeout = null): int
    {
        // Backstop; the remote `timeout` in execute() is the real bound (class docblock).
        $deadline = microtime(true) + ($timeout ?? $this->commandTimeout) + 30;
        $pattern = '/RC:(\d+):' . preg_quote($this->marker, '/') . '/';

        while (true) {
            if (microtime(true) > $deadline) {
                throw new SshException(
                    'Timed out after ' . ($timeout ?? $this->commandTimeout) . 's waiting for the command to finish.'
                );
            }

            // ONE read per iteration — see the class docblock.
            $chunk = fread($this->shell, self::READ_CHUNK);
            if ($chunk !== false && $chunk !== '') {
                $this->buffer .= $chunk;
                $this->log($this->stripMarker($chunk));
            } else {
                Coroutine::sleep(0.01);
            }

            if (preg_match($pattern, $this->buffer, $matches)) {
                $this->buffer = '';
                return (int) $matches[1];
            }

            foreach ($prompts as $i => $prompt) {
                if (!empty($prompt['used']) || empty($prompt['keywords'])) {
                    continue;
                }
                $matched = true;
                foreach ($prompt['keywords'] as $keyword) {
                    $matched = $matched && str_contains($this->buffer, $keyword);
                }
                if ($matched) {
                    $prompts[$i]['used'] = true;
                    fwrite($this->shell, $prompt['input'] . "\n");
                    // Drop what has been consumed so a later prompt cannot re-match on it.
                    $this->buffer = '';
                }
            }
        }
    }

    // ---------------------------------------------------------------- files

    /**
     * Write content to a remote path, and prove it arrived.
     *
     * The content goes over the COMMAND CHANNEL as base64 on stdin — `base64 -d > path` — not
     * through `ssh2_scp_send()`. SCP was the previous transport and it silently lost the payload:
     * on Swoole's coroutine ssh2 it created the destination with the right name and mode, returned
     * SUCCESS, and wrote ZERO bytes. That is how a key-vault push reported success and left a
     * 0-byte vault on the server (deploy history #28/#29) — after which the build read an empty
     * vault, substituted nothing, and the release ran on placeholder secrets. The same failure was
     * invisible for as long as nobody counted the bytes on the far side.
     *
     * This path has none of that: it reuses the connection that runs every other command, it is one
     * process with a real exit code, and the size is checked afterwards. base64 rather than raw
     * bytes so no byte in the content can be read as a shell or protocol character, and because
     * stdin over a channel is a text pipe.
     *
     * NOTE: writes as the LOGIN user — the target directory must be writable by it.
     *
     * @param int $mode permission bits for the created file; secrets should pass 0600. `umask 077`
     *                  applies before the file is created, so it is never world-readable even for
     *                  the instant before the chmod.
     */
    public function writeFile(string $remotePath, string $content, int $mode = 0644): void
    {
        $this->requireConnection();
        $length = strlen($content);
        $this->log("Writing $length bytes to $remotePath\n");

        $result = $this->exec(
            'umask 077 && base64 -d > ' . escapeshellarg($remotePath),
            false,
            null,
            base64_encode($content) . "\n"
        );
        if (!$result->ok()) {
            $reason = trim($result->stderr !== '' ? $result->stderr : $result->text());
            throw new SshException(
                "Could not write $remotePath (exit " . ($result->exitCode ?? '?') . ')'
                    . ($reason !== '' ? ': ' . $this->scrub($reason) : '.')
            );
        }

        // Verified HERE rather than by the caller: a short or empty write is silent by nature —
        // every byte that did arrive is written, and what is left behind still looks like a file.
        $written = (int) trim($this->exec('wc -c < ' . escapeshellarg($remotePath))->text());
        if ($written !== $length) {
            throw new SshException(
                "Wrote $written of $length bytes to $remotePath — the transfer was truncated, so the "
                    . 'file there is NOT what was sent.'
            );
        }

        $this->exec('chmod ' . sprintf('%04o', $mode) . ' ' . escapeshellarg($remotePath));
    }

    public function sendFile(string $localPath, string $remotePath): void
    {
        $content = @file_get_contents($localPath);
        if ($content === false) {
            throw new SshException("Could not read local file: $localPath");
        }
        $this->writeFile($remotePath, $content);
    }

    // ---------------------------------------------------------------- output

    /** Mask this value everywhere this service emits text. Call BEFORE the secret can appear. */
    public function addSecret(?string $secret): void
    {
        if ($secret !== null && strlen($secret) >= 4) {
            $this->secrets[] = $secret;
        }
    }

    public function scrub(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, '******', $text);
        }
        return $text;
    }

    /** Everything this session has emitted, scrubbed. */
    public function transcript(): string
    {
        return $this->transcript;
    }

    /**
     * Write a line into the transcript and the live stream without running anything remotely —
     * for callers that need to explain what they are doing (or warn) in the same log the
     * commands appear in.
     */
    public function note(string $line): void
    {
        $this->log(rtrim($line, "\n") . "\n");
    }

    private function log(string $text): void
    {
        if ($text === '') {
            return;
        }
        $clean = $this->scrub($text);
        $this->transcript .= $clean;
        if ($this->onOutput !== null) {
            ($this->onOutput)($clean);
        }
    }

    private function stripMarker(string $text): string
    {
        $text = preg_replace('/RC:\d+:' . preg_quote($this->marker, '/') . '/', '', $text) ?? $text;
        return str_replace($this->markerCommand, '', $text);
    }

    // ---------------------------------------------------------------- lifecycle

    private function requireConnection(): void
    {
        if ($this->connection === null) {
            throw new SshException('Not connected — call connect() first.');
        }
    }

    private function writeKeyFile(string $prefix, string $contents): string
    {
        $path = tempnam($this->tempDir, "ssh_{$prefix}_");
        if ($path === false) {
            throw new SshException("Could not create a temp file in {$this->tempDir}.");
        }
        // 0600 before the key lands: tempnam creates 0600 already, but an umask-dependent
        // assumption is not worth making for private key material.
        @chmod($path, 0600);
        if (file_put_contents($path, $contents) === false) {
            @unlink($path);
            throw new SshException("Could not write the key file $path.");
        }
        return $path;
    }

    public function disconnect(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;
        try {
            if ($this->shell) {
                @fclose($this->shell);
            }
            if ($this->connection) {
                @ssh2_disconnect($this->connection);
            }
        } catch (Throwable) {
            // Tearing down is best-effort; the key files below matter more.
        } finally {
            $this->shell = null;
            $this->connection = null;
            foreach ([$this->privateKeyFile, $this->publicKeyFile] as $file) {
                if ($file !== null && is_file($file)) {
                    @unlink($file);
                }
            }
            $this->privateKeyFile = null;
            $this->publicKeyFile = null;
        }
    }

    public function dispose()
    {
        $this->disconnect();
    }
}
