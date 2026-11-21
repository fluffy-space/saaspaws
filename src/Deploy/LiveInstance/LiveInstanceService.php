<?php

namespace SaasPaws\Deploy\LiveInstance;

/**
 * Is THIS instance the one taking traffic?
 *
 * Cron runs in-process (scheduled by the app's `Application/crontab.php` and each SaasPaws
 * module's StartUp::configure()), so during a blue/green rotation both
 * colours are up and every scheduled job fires TWICE — duplicate emails, the same work done
 * twice. The overlap is short (the old colour is stopped after the drain window) but a
 * 10-second job fires several times inside it, and an admin who starts both units by hand
 * extends it indefinitely.
 *
 * The signal is a marker file written by the rotator at `<releaseRoot>/live-color` — a sibling
 * of the release directories, so both colours read the same file. A file rather than Redis
 * because the deploy runs from a *different machine*: it already has an SSH session to the
 * target and cannot reach the target's Redis.
 *
 * FAIL-OPEN: no marker means this box was never rotated by the deploy tool (a plain
 * single-instance install, or dev), and there cron must run normally. Fail-closed there would
 * silently stop every scheduled job on a box that is working perfectly well.
 */
class LiveInstanceService
{
    private const CACHE_SECONDS = 10;

    private ?bool $cached = null;
    private int $cachedAt = 0;

    /**
     * The release directory name IS the colour — the same convention configs/server.php uses to
     * resolve this instance's port. Dev runs from the repo directory and matches nothing.
     */
    public function currentColor(): string
    {
        return basename(getcwd());
    }

    /** Path of the marker, a sibling of the release directories. */
    public function markerPath(): string
    {
        return dirname(getcwd()) . '/live-color';
    }

    public function liveColor(): ?string
    {
        $path = $this->markerPath();
        if (!is_file($path)) {
            return null;
        }
        $value = trim((string) @file_get_contents($path));
        return $value !== '' ? $value : null;
    }

    /**
     * Cached for a few seconds: a job that fires every 10s must not stat the filesystem on every
     * tick, and a rotation only needs to be noticed within seconds — the drain window is longer
     * than this cache by design.
     */
    public function isLive(): bool
    {
        $now = time();
        if ($this->cached !== null && $now - $this->cachedAt < self::CACHE_SECONDS) {
            return $this->cached;
        }
        $live = $this->liveColor();
        $this->cached = $live === null || $live === $this->currentColor();
        $this->cachedAt = $now;
        return $this->cached;
    }

    /**
     * Guard for the top of a scheduled task: true means "skip this tick".
     * Logs once per skip so a silent cron on a box is explainable from the journal.
     */
    public function shouldSkipCron(string $jobName): bool
    {
        if ($this->isLive()) {
            return false;
        }
        echo "[Cron] $jobName skipped — instance '{$this->currentColor()}' is not live ("
            . ($this->liveColor() ?? 'unknown') . " is).\n";
        return true;
    }
}
