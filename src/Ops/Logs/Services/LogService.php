<?php

namespace SaasPaws\Ops\Logs\Services;

use Fluffy\Domain\Configuration\Config;

/**
 * Reads the application's systemd journal for the admin log viewer, with cursor-based paging.
 *
 * The app runs as a `Type=exec` systemd daemon, so every `echo` it makes goes to
 * stdout/stderr and is captured by journald (which also rotates itself). Rather than
 * maintain a separate log file, we read that journal back with `journalctl -u <unit>`.
 *
 * Blue/green deploys run TWO units that swap in and out; config lists all of them and we
 * pass one `-u` per unit, so journalctl MERGES them into a single time-ordered stream
 * (its `__CURSOR` ids are global — stable across units — so paging is untouched). Each
 * entry carries `_SYSTEMD_UNIT`, which we map back to a label/colour so the UI can tag
 * every line by its source; an optional `$unit` filter narrows the stream to one unit.
 *
 * Paging uses journald cursors (stable per-entry ids), not offsets — so pages don't
 * drift as new entries arrive. `-o json` gives each line's `__CURSOR`; we return the
 * boundary cursors (oldest/newest shown) plus hasOlder/hasNewer, and accept `before`
 * (older page) / `after` (newer page) to navigate. We over-fetch by one to detect
 * whether more entries exist in the fetch direction.
 *
 * Security: unit names come from config (trusted, never user input) — a client-supplied
 * `$unit` filter is only ever matched against that whitelist, never shelled directly;
 * `lines` is clamped, `priority` is whitelisted, and cursors (round-tripped from the
 * client) are format-validated AND escapeshellarg'd before reaching the shell.
 *
 * Availability degrades gracefully (available => false) when there is no journal —
 * dev runs (no systemd), journalctl missing, or the process user lacks read permission.
 */
class LogService
{
    private const MAX_LINES = 2000;
    private const DEFAULT_LINES = 200;

    /** Ordered syslog severities accepted for the `-p` filter (value = message at this level or higher). */
    private const PRIORITIES = ['emerg', 'alert', 'crit', 'err', 'warning', 'notice', 'info', 'debug'];

    /** @var array<int, array{unit: string, label: string, color: string}> Configured units, in display order. */
    private array $units;

    function __construct(Config $config)
    {
        $this->units = $this->readUnits($config);
    }

    /**
     * Normalise the `journal` config into a units list. Accepts the new `units` array form
     * and the legacy single `unit` string, so an un-migrated vault keeps working.
     *
     * @return array<int, array{unit: string, label: string, color: string}>
     */
    private function readUnits(Config $config): array
    {
        $journal = $config->values['journal'] ?? [];
        $raw = $journal['units'] ?? null;
        if (!is_array($raw) || $raw === []) {
            $legacy = (string)($journal['unit'] ?? '');
            $raw = $legacy !== '' ? [['unit' => $legacy]] : [];
        }
        $units = [];
        foreach ($raw as $entry) {
            $name = is_array($entry) ? (string)($entry['unit'] ?? '') : (string)$entry;
            if ($name === '') {
                continue;
            }
            $units[] = [
                'unit' => $name,
                'label' => is_array($entry) && ($entry['label'] ?? '') !== '' ? (string)$entry['label'] : $name,
                'color' => is_array($entry) && ($entry['color'] ?? '') !== '' ? (string)$entry['color'] : 'secondary',
            ];
        }
        return $units;
    }

    /**
     * A page of journal entries for the app unit, chronological (oldest first, newest last).
     *
     * @param ?string $before cursor of the current oldest entry — fetch the page BEFORE it (older).
     * @param ?string $after  cursor of the current newest entry — fetch the page AFTER it (newer).
     *                        Neither set => the latest page (tail).
     * @param ?string $unit   restrict the merged stream to a single configured unit (by name);
     *                        null/unknown => all units (the merged view).
     * @return array{available: bool, units: array, activeUnit: string, multiUnit: bool, priority: ?string,
     *               count: int, lines: array<int, array{text: string, label: string, color: string}>,
     *               firstCursor: ?string, lastCursor: ?string, hasOlder: bool, hasNewer: bool, error?: string}
     */
    function page(int $lines = self::DEFAULT_LINES, ?string $priority = null, ?string $before = null, ?string $after = null, ?string $unit = null): array
    {
        $priority = $this->normalizePriority($priority);
        $activeUnits = $this->resolveUnits($unit);
        $result = [
            'available' => false,
            'units' => $this->units,
            'activeUnit' => $activeUnits === $this->units ? '' : ($activeUnits[0]['unit'] ?? ''),
            'multiUnit' => count($this->units) > 1,
            'priority' => $priority,
            'count' => 0,
            'lines' => [],
            'firstCursor' => null,
            'lastCursor' => null,
            'hasOlder' => false,
            'hasNewer' => false,
        ];

        if ($this->units === []) {
            $result['error'] = 'No journal unit configured (config: journal.units).';
            return $result;
        }

        $bin = trim((string)shell_exec('command -v journalctl 2>/dev/null'));
        if ($bin === '') {
            $result['error'] = 'journalctl not available — no systemd journal on this host (expected in dev).';
            return $result;
        }

        $lines = max(1, min(self::MAX_LINES, $lines));
        $before = $this->sanitizeCursor($before);
        $after = $this->sanitizeCursor($after);

        $base = escapeshellarg($bin);
        foreach ($activeUnits as $u) {
            $base .= ' -u ' . escapeshellarg($u['unit']);
        }
        $base .= ' -o json --no-pager --output-fields=MESSAGE,SYSLOG_IDENTIFIER,_PID,_SYSTEMD_UNIT';
        if ($priority !== null) {
            $base .= ' -p ' . escapeshellarg($priority);
        }

        if ($before !== null) {
            // Entries OLDER than $before: start at the cursor, walk backward (newest-first),
            // then drop the boundary entry itself. Over-fetch by 2 (boundary + one probe).
            $cmd = $base . ' --cursor=' . escapeshellarg($before) . ' -r -n ' . ($lines + 2);
            $entries = $this->parse($this->exec($cmd, $result));
            if (isset($result['error'])) {
                return $result;
            }
            if (!empty($entries) && $entries[0]['cursor'] === $before) {
                array_shift($entries); // remove the boundary (already shown on the newer page)
            }
            $result['hasOlder'] = count($entries) > $lines;
            $entries = array_slice($entries, 0, $lines);
            $entries = array_reverse($entries); // back to chronological
            $result['hasNewer'] = true;         // we came from a newer page
        } elseif ($after !== null) {
            // Entries NEWER than $after, chronological. Over-fetch by 1 to detect more.
            $cmd = $base . ' --after-cursor=' . escapeshellarg($after) . ' -n ' . ($lines + 1);
            $entries = $this->parse($this->exec($cmd, $result));
            if (isset($result['error'])) {
                return $result;
            }
            $result['hasNewer'] = count($entries) > $lines;
            $entries = array_slice($entries, 0, $lines);
            $result['hasOlder'] = true;         // we came from an older page
        } else {
            // Latest page (tail): last N (+1 to detect older). Chronological.
            $cmd = $base . ' -n ' . ($lines + 1);
            $entries = $this->parse($this->exec($cmd, $result));
            if (isset($result['error'])) {
                return $result;
            }
            $result['hasOlder'] = count($entries) > $lines;
            if (count($entries) > $lines) {
                $entries = array_slice($entries, count($entries) - $lines, $lines);
            }
            $result['hasNewer'] = false;        // already at the newest end
        }

        $display = [];
        foreach ($entries as $e) {
            $display[] = ['text' => $e['line'], 'label' => $e['label'], 'color' => $e['color']];
        }

        $result['available'] = true;
        $result['count'] = count($entries);
        $result['lines'] = $display;
        $result['firstCursor'] = empty($entries) ? null : $entries[0]['cursor'];
        $result['lastCursor'] = empty($entries) ? null : $entries[count($entries) - 1]['cursor'];
        return $result;
    }

    /** Run a journalctl command, mapping the common failures onto $result['error']. */
    private function exec(string $cmd, array &$result): string
    {
        $raw = shell_exec($cmd . ' 2>&1');
        if ($raw === null) {
            $result['error'] = 'Failed to run journalctl.';
            return '';
        }
        $raw = trim($raw);
        if (stripos($raw, 'permission') !== false && stripos($raw, 'denied') !== false
            || stripos($raw, 'not have permission') !== false
        ) {
            $result['error'] = 'Permission denied reading the journal — add the app user to the systemd-journal group.';
            return '';
        }
        return $raw;
    }

    /**
     * The configured units to query for this request: just the named one if `$unit` matches
     * a configured unit, else all of them (the merged view). A client-supplied name that
     * isn't in the whitelist falls back to all — it never reaches the shell.
     *
     * @return array<int, array{unit: string, label: string, color: string}>
     */
    private function resolveUnits(?string $unit): array
    {
        $unit = trim((string)$unit);
        if ($unit === '') {
            return $this->units;
        }
        foreach ($this->units as $u) {
            if ($u['unit'] === $unit) {
                return [$u];
            }
        }
        return $this->units;
    }

    /** Map a `_SYSTEMD_UNIT` value back to its configured label/colour (unknown => neutral). */
    private function unitMeta(string $systemdUnit): array
    {
        foreach ($this->units as $u) {
            if ($u['unit'] === $systemdUnit) {
                return ['label' => $u['label'], 'color' => $u['color']];
            }
        }
        return ['label' => $systemdUnit, 'color' => 'secondary'];
    }

    /**
     * Parse `-o json` output into entries with a display line, cursor, and source tag,
     * in the order journalctl emitted them.
     *
     * @return array<int, array{cursor: string, line: string, label: string, color: string}>
     */
    private function parse(string $raw): array
    {
        $entries = [];
        if ($raw === '') {
            return $entries;
        }
        foreach (explode("\n", $raw) as $line) {
            $obj = json_decode($line, true);
            if (!is_array($obj)) {
                continue;
            }
            $micro = (int)($obj['__REALTIME_TIMESTAMP'] ?? 0);
            $ts = $micro > 0 ? gmdate('Y-m-d H:i:s', intdiv($micro, 1000000)) : '';
            $ident = (string)($obj['SYSLOG_IDENTIFIER'] ?? '');
            $pid = (string)($obj['_PID'] ?? '');
            $message = $this->messageText($obj['MESSAGE'] ?? '');
            $meta = $this->unitMeta((string)($obj['_SYSTEMD_UNIT'] ?? ''));

            $prefix = $ts;
            if ($ident !== '') {
                $prefix .= ' ' . $ident . ($pid !== '' ? '[' . $pid . ']' : '') . ':';
            }
            $entries[] = [
                'cursor' => (string)($obj['__CURSOR'] ?? ''),
                'line' => trim($prefix . ' ' . $message),
                'label' => $meta['label'],
                'color' => $meta['color'],
            ];
        }
        return $entries;
    }

    /** journald MESSAGE is usually a string, but non-UTF8 payloads arrive as an array of byte values. */
    private function messageText($message): string
    {
        if (is_array($message)) {
            $bytes = '';
            foreach ($message as $b) {
                $bytes .= chr((int)$b);
            }
            $message = $bytes;
        }
        // Strip ANSI escape sequences (colours etc. some units emit) so they don't render as noise.
        return (string)preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', (string)$message);
    }

    /** Whitelist a requested priority to a valid syslog severity token, else null (no filter). */
    private function normalizePriority(?string $priority): ?string
    {
        $priority = strtolower(trim((string)$priority));
        if ($priority === '') {
            return null;
        }
        if (ctype_digit($priority)) {
            $i = (int)$priority;
            return $i >= 0 && $i <= 7 ? self::PRIORITIES[$i] : null;
        }
        return in_array($priority, self::PRIORITIES, true) ? $priority : null;
    }

    /**
     * Journald cursors look like "s=..;i=..;b=..;m=..;t=..;x=..". Accept only that safe charset
     * (defence in depth on top of escapeshellarg); reject anything else to null (ignored).
     */
    private function sanitizeCursor(?string $cursor): ?string
    {
        $cursor = trim((string)$cursor);
        if ($cursor === '') {
            return null;
        }
        return preg_match('/^[A-Za-z0-9=;_.\/+-]{1,512}$/', $cursor) ? $cursor : null;
    }
}
