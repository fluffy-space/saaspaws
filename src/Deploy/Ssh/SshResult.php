<?php

namespace SaasPaws\Deploy\Ssh;

/**
 * The outcome of one remote command. `exitCode` is the point of this class: shell-scraping
 * transports never had it, which is how a pipeline could carry on after a failed build.
 */
class SshResult
{
    public function __construct(
        public string $command,
        public string $output,
        public string $stderr,
        public ?int $exitCode,
        public float $durationMs,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /** stdout, or stderr when the command failed silently on stdout. Trimmed for convenience. */
    public function text(): string
    {
        $out = trim($this->output);
        return $out !== '' ? $out : trim($this->stderr);
    }
}
