<?php

namespace SaasPaws\Deploy\Ssh;

/**
 * How to reach one box over SSH. A plain value object on purpose: the framework must not know
 * about an application's server entity, and tests can build one without a database.
 *
 * Key material is held as PEM/OpenSSH TEXT, not paths — callers keep it encrypted at rest and
 * hand over the decrypted bytes; SshService writes them to 0600 temp files only for the
 * duration of the handshake and unlinks them afterwards.
 */
class SshCredentials
{
    public function __construct(
        public string $host,
        public string $userName,
        public int $port = 22,
        public ?string $privateKey = null,
        public ?string $publicKey = null,
        public ?string $passphrase = null,
        /**
         * The login user's password, used for sudo. Written to `sudo -S` on STDIN, never into a command
         * line — argv is world-readable through `ps` and would land in the deploy transcript.
         * Null for a root login or a NOPASSWD sudoers entry.
         */
        public ?string $sudoPassword = null,
        /** Optional label used in log lines ("myapp-prod"). Falls back to host. */
        public ?string $label = null,
    ) {}

    public function describe(): string
    {
        return ($this->label ?? $this->host) . " ({$this->userName}@{$this->host}:{$this->port})";
    }
}
