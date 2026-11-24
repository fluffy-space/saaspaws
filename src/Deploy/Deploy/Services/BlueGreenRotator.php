<?php

namespace SaasPaws\Deploy\Deploy\Services;

use SaasPaws\Deploy\Ssh\SshService;
use Swoole\Coroutine;

/**
 * Moves traffic between the slots of one application on one box.
 *
 * The nginx upstream is the single source of truth for "which slot is live": its
 * `server 127.0.0.1:<port>` line is both what routes traffic and what this class reads to work
 * out the current state. `DeployApplication.LiveSlot` is only ever a last-observed note for the
 * UI — nothing here trusts it.
 *
 * It works in PORTS, not colours. Which slot a port belongs to is the caller's business, because
 * only the caller has the `DeployService` rows that say so; an application may have several
 * upstreams (`/`, `/api`) each with its own pair of ports, so there is no single colour→port map
 * to hold here any more. That is the whole difference from the version this replaces, which read
 * one such map out of the target's `configs/server.php`.
 *
 * Every step is idempotent and safe to re-run: repointing an upstream that is already on the
 * wanted port is a no-op, and health checks are polls rather than one-shot assertions.
 */
class BlueGreenRotator
{
    public function __construct(
        private SshService $ssh,
        /** Overridable so a test can neutralise the privileged bits. */
        public string $configTestCommand = 'openresty -t',
        public string $reloadCommand = 'openresty -s reload',
    ) {}

    // ------------------------------------------------------------------ upstreams

    /** The port an upstream conf currently sends traffic to, or null if it says nothing. */
    public function readUpstreamPort(string $confPath): ?int
    {
        $conf = $this->ssh->exec('cat ' . escapeshellarg($confPath), enforce: false);
        if (!$conf->ok()) {
            return null;
        }
        // `server 127.0.0.1:8221 fail_timeout=1;`: the port is what identifies the slot. The first
        // line that is not a standby: Fluffy's builder writes one line per colour and marks every
        // one but the live one `down`.
        if (!preg_match_all('/^\s*server\s+127\.0\.0\.1:(\d+)([^;]*);/m', $conf->output, $all, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($all as $line) {
            if (!preg_match('/\bdown\b/', $line[2])) {
                return (int) $line[1];
            }
        }
        return null;
    }

    /**
     * The upstream file pointed at $port. With one server line per colour (Fluffy's builder) the
     * `down` marker moves: the line already on $port becomes the live one and the others standbys,
     * so each line keeps its port and its colour comment. A file with no line on $port (one server
     * line, hand-written) gets its live line's port rewritten, as before.
     */
    public static function moveTo(string $conf, int $port): string
    {
        $lines = preg_split('/\R/', $conf);
        $hasTarget = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*server\s+127\.0\.0\.1:' . $port . '\b/', $line)) {
                $hasTarget = true;
            }
        }
        foreach ($lines as $i => $line) {
            if (!preg_match('/^(\s*server\s+127\.0\.0\.1:)(\d+)([^;]*);(.*)$/', $line, $m)) {
                continue;
            }
            [, $head, $linePort, $options, $tail] = $m;
            $isDown = (bool) preg_match('/\bdown\b/', $options);
            if (!$hasTarget) {
                if (!$isDown) {
                    $lines[$i] = $head . $port . $options . ';' . $tail;
                }
                continue;
            }
            if ((int) $linePort === $port) {
                $options = trim((string) preg_replace('/\s*\bdown\b/', '', $options));
                $options = $options === '' ? ' fail_timeout=1' : ' ' . $options;
                $lines[$i] = $head . $linePort . $options . ';' . str_replace('(standby)', '(ACTIVE)', $tail);
            } elseif (!$isDown) {
                $options = trim((string) preg_replace('/\s*\bfail_timeout=\S+/', '', $options));
                $lines[$i] = $head . $linePort . ($options === '' ? '' : ' ' . $options) . ' down;' . str_replace('(ACTIVE)', '(standby)', $tail);
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Repoint every upstream at once, then validate and reload the edge ONCE.
     *
     * All-or-nothing on purpose: an application whose `/` and `/api` upstreams end up pointing at
     * different slots is a worse state than not having rotated at all, so the confs are rewritten
     * together, tested together, and all restored from their backups if the test fails. nginx
     * keeps serving the old config throughout — it only ever sees the new one if `-t` passed.
     *
     * @param array $moves [['conf' => path, 'port' => int, 'label' => string], ...]
     */
    public function repoint(array $moves): void
    {
        $pending = [];
        foreach ($moves as $move) {
            if ($this->readUpstreamPort($move['conf']) === (int) $move['port']) {
                continue; // already there — rotating twice must not be an error
            }
            $pending[] = $move;
        }
        if (count($pending) === 0) {
            return;
        }

        $backups = [];
        foreach ($pending as $move) {
            $conf = escapeshellarg($move['conf']);
            $backup = $move['conf'] . '.rotate-backup';
            $this->ssh->sudo("cp -f $conf " . escapeshellarg($backup));
            $backups[] = ['conf' => $move['conf'], 'backup' => $backup];
            // Only the loopback server lines are touched (moveTo()); everything else in the file is
            // left alone: these files are hand-editable and may carry keepalive tuning. Written to a
            // temporary file by the login, then copied over the conf as root.
            $staged = '/tmp/' . basename($move['conf']) . '.rotate-' . bin2hex(random_bytes(4));
            $current = $this->ssh->read('cat ' . $conf);
            if (str_contains($current, '******')) {
                // What came back was masked (a secret value appears in the file): writing it back
                // would put the stars into the live config.
                throw new DeployException("{$move['conf']} reads back masked; repoint it by hand.", 'rotate');
            }
            $this->ssh->writeFile($staged, self::moveTo($current, (int) $move['port']), 0644);
            $this->ssh->sudo('cp -f ' . escapeshellarg($staged) . " $conf");
            $this->ssh->exec('rm -f ' . escapeshellarg($staged), false);
        }

        $test = $this->ssh->sudo($this->configTestCommand, enforce: false);
        if (!$test->ok()) {
            foreach ($backups as $entry) {
                $this->ssh->sudo(
                    'cp -f ' . escapeshellarg($entry['backup']) . ' ' . escapeshellarg($entry['conf']),
                    enforce: false
                );
            }
            throw new DeployException(
                "The edge rejected the new upstreams; restored the previous configuration.\n" . $test->text(),
                'rotate'
            );
        }
        $this->ssh->sudo($this->reloadCommand);

        // Read back rather than trust the sed: a conf whose upstream line does not match the
        // pattern would have been rewritten silently to nothing at all.
        foreach ($pending as $move) {
            $now = $this->readUpstreamPort($move['conf']);
            if ($now !== (int) $move['port']) {
                throw new DeployException(
                    "Upstream '{$move['label']}' did not move to port {$move['port']} — "
                        . "{$move['conf']} still reads " . ($now === null ? 'nothing' : (string) $now) . '.',
                    'rotate'
                );
            }
        }
    }

    /**
     * Tell the instances themselves which slot is live.
     *
     * Cron runs in-process in every running instance, so without this both slots fire every
     * scheduled job during the overlap — duplicate emails, double queue drains. The instances
     * read this file (`LiveInstanceService`); nginx remains the routing truth, this is only how
     * a process learns whether it is the chosen one.
     *
     * Written AFTER the upstreams flipped, so a failed rotation cannot leave the marker pointing
     * at a slot that is not serving. A failure to write is not fatal: traffic has already moved,
     * and a missing marker fails open to "cron runs" rather than stopping it.
     */
    public function writeLiveMarker(string $baseFolder, string $slot): void
    {
        $path = rtrim($baseFolder, '/') . '/live-color';
        $result = $this->ssh->sudo(
            'sh -c ' . escapeshellarg('printf %s ' . escapeshellarg($slot) . ' > ' . escapeshellarg($path)),
            enforce: false
        );
        if (!$result->ok()) {
            $this->ssh->note(
                "WARNING: could not write the live-slot marker at $path — both instances may run "
                    . 'their cron jobs while they overlap.'
            );
        }
    }

    // ------------------------------------------------------------------ units

    public function startUnit(string $unit): void
    {
        $this->ssh->sudo('systemctl start ' . escapeshellarg($unit));
    }

    public function restartUnit(string $unit): void
    {
        $this->ssh->sudo('systemctl restart ' . escapeshellarg($unit));
    }

    public function isRunning(string $unit): bool
    {
        return $this->ssh->test('systemctl is-active --quiet ' . escapeshellarg($unit));
    }

    /**
     * Stop a unit AFTER a delay, detached from this SSH session.
     *
     * The deploy may well be running inside the instance being stopped (the control plane is the
     * same application), so a synchronous `systemctl stop` would kill the process issuing it —
     * losing the log and the exit status. `systemd-run --on-active` hands the job to systemd and
     * returns at once.
     */
    public function stopDetached(string $unit, int $drainSeconds): void
    {
        $this->ssh->sudo(
            "systemd-run --on-active={$drainSeconds}s --unit=" . escapeshellarg("drain-$unit")
                . ' --collect /bin/systemctl stop ' . escapeshellarg($unit)
        );
    }

    // ------------------------------------------------------------------ health

    /**
     * Poll an instance's health endpoint on the loopback until it has answered 200 *steadily*.
     *
     * Loopback, not the public domain: at this point the instance is deliberately NOT in any
     * upstream, so the only way to ask it anything is directly.
     *
     * ONE 200 IS NOT ENOUGH. The HTTP server accepts connections before the application has
     * finished wiring its container and running its start-up work, so the first 200 can come from
     * a process that is still booting — rotate onto that and the first real requests hit a
     * half-initialised instance. The endpoint therefore has to keep answering for
     * `$settleSeconds` before this returns, and any non-200 in between resets the clock, so a
     * worker that crashes and restarts during boot cannot be mistaken for a healthy one.
     */
    public function waitHealthy(
        string $unit,
        int $port,
        string $healthPath,
        int $timeoutSeconds = 60,
        int $settleSeconds = 5
    ): void {
        $url = 'http://127.0.0.1:' . $port . $healthPath;
        $deadline = microtime(true) + $timeoutSeconds;
        $healthySince = null;
        $lastCode = '000';

        while (microtime(true) < $deadline) {
            $lastCode = $this->httpCode($url, 3);
            if ($lastCode === '200') {
                if ($healthySince === null) {
                    $healthySince = microtime(true);
                }
                if (microtime(true) - $healthySince >= $settleSeconds) {
                    return;
                }
            } else {
                $healthySince = null;
            }
            Coroutine::sleep(1.0);
        }
        throw new DeployException(
            $healthySince === null
                ? "'$unit' never became healthy at $url (last status $lastCode after {$timeoutSeconds}s)."
                : "'$unit' answered at $url but did not stay healthy for {$settleSeconds}s within "
                    . "{$timeoutSeconds}s (last status $lastCode).",
            'start'
        );
    }

    /** Health through the edge — proves the rotation actually moved public traffic. */
    public function verifyThroughEdge(?string $domain, string $healthPath, int $timeoutSeconds = 30): void
    {
        if ($domain === null || trim($domain) === '') {
            return;
        }
        $url = 'https://' . $domain . $healthPath;
        $deadline = microtime(true) + $timeoutSeconds;
        $lastCode = '000';

        while (microtime(true) < $deadline) {
            $lastCode = $this->httpCode($url, 5);
            if ($lastCode === '200') {
                return;
            }
            Coroutine::sleep(1.0);
        }
        throw new DeployException(
            "The site did not answer 200 at $url after the rotation (last status $lastCode).",
            'verify'
        );
    }

    private function httpCode(string $url, int $timeout): string
    {
        // --fail-with-body would change the exit code; we want the status either way, so the
        // command must not be enforced.
        $result = $this->ssh->exec(
            "curl -s -o /dev/null -w '%{http_code}' --max-time $timeout " . escapeshellarg($url),
            enforce: false,
            timeout: $timeout + 5
        );
        return trim($result->output) !== '' ? trim($result->output) : '000';
    }
}
