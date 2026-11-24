<?php

namespace SaasPaws\Deploy\Deploy\Components\Views;

use Viewi\Components\BaseComponent;
use Viewi\Components\Environment\ClientTimer;
use Viewi\Components\Http\HttpClient;

/**
 * The Deployments tab: the button, the confirmation, the live log and the history.
 *
 * One button and an "are you sure?" modal — no elevation, no preview token, no typed
 * confirmation. Those were dropped in the rework; the capability (SuperAdmin-only) and the
 * dev-only routes are the gate.
 *
 * While a run is in flight the page tails `/deploy/run/{runId}/logs?offset=` once a second and
 * appends. Polling rather than a socket: the deploy runs in a task worker and a *different*
 * worker serves these reads, so the log has to be a shared artifact either way.
 */
class DeployRunPanel extends BaseComponent
{
    /** Inputs from the edit page. */
    public int $application = 0;
    public string $name = '';

    public array $history = [];
    public bool $loading = true;

    // --- confirmation ----------------------------------------------------------------
    public bool $showConfirm = false;
    /** deploy | vault | restart | check */
    public string $kind = 'deploy';
    public bool $starting = false;
    public string $error = '';
    /** A property, not a zero-arg method — those parse as property access in a template. */
    public string $confirmMessage = '';
    public string $confirmLabel = 'deploy';

    // --- live run --------------------------------------------------------------------
    public int $runId = 0;
    public string $log = '';
    public int $logOffset = 0;
    public bool $running = false;

    /** Whether the stored-log modal is open, and its already-flattened caption. */
    public bool $showRecord = false;
    public string $openTitle = '';
    public string $openLog = '';

    public function __construct(private HttpClient $http) {}

    public function mounted()
    {
        $this->loadHistory();
    }

    public function loadHistory()
    {
        if ($this->application === 0) {
            return;
        }
        $this->http->get("/api/admin/deploy/{$this->application}/history?page=1&size=20")
            ->then(function ($data) {
                $this->history = $data['list'];
                $this->loading = false;
            }, function () {
                $this->loading = false;
            });
    }

    // ---------------------------------------------------------------- confirm + start

    public function askDeploy()
    {
        $this->kind = 'deploy';
        $this->error = '';
        $this->confirmMessage = "Deploy {$this->name} to every server attached to it?";
        $this->confirmLabel = 'deploy';
        $this->showConfirm = true;
    }

    public function askVault()
    {
        $this->kind = 'vault';
        $this->error = '';
        $this->confirmMessage = "Write the key vault to every server of {$this->name}?"
            . ' It overwrites the file on the box.';
        $this->confirmLabel = 'write the vault';
        $this->showConfirm = true;
    }

    /** For after the server was updated (PHP, Swoole, the OS): same release, fresh processes. */
    public function askRestart()
    {
        $this->kind = 'restart';
        $this->error = '';
        $this->confirmMessage = "Restart {$this->name} on every server attached to it?"
            . ' Nothing is built: the live release is copied into the idle slot and traffic rotates to it.'
            . ' An application on the restart strategy restarts in place, with a short outage.';
        $this->confirmLabel = 'restart';
        $this->showConfirm = true;
    }

    /** Read-only: looks at the servers and reports, changes nothing (DeploymentService::check). */
    public function askCheck()
    {
        $this->kind = 'check';
        $this->error = '';
        $this->confirmMessage = "Check every server of {$this->name}?"
            . ' It only looks: prerequisites, what is already there, and what of other applications'
            . ' is in the way. It changes no configuration and restarts nothing.';
        $this->confirmLabel = 'check';
        $this->showConfirm = true;
    }

    public function cancel()
    {
        $this->showConfirm = false;
    }

    public function start()
    {
        $this->starting = true;
        $this->error = '';
        $action = $this->kind === 'deploy' ? 'run' : $this->kind;
        $this->http->post("/api/admin/deploy/{$this->application}/$action", [])
            ->then(function ($data) {
                $this->starting = false;
                $this->showConfirm = false;
                $this->runId = $data['runId'];
                $this->log = '';
                $this->logOffset = 0;
                $this->running = true;
                $this->pollLog();
            }, function ($response) {
                $this->starting = false;
                $this->error = 'Could not start. The server refused the request.';
            });
    }

    // ---------------------------------------------------------------- live log

    public function pollLog()
    {
        if (!$this->running || $this->runId === 0) {
            return;
        }
        $this->http->get("/api/admin/deploy/run/{$this->runId}/logs?offset={$this->logOffset}")
            ->then(function ($data) {
                if ($data['chunk'] !== '') {
                    // Assign a NEW string — appending in place would not re-render.
                    $this->log = $this->log . $data['chunk'];
                    $this->logOffset = $data['offset'];
                    $this->scrollLog();
                }
                if ($this->isFinished()) {
                    $this->running = false;
                    $this->loadHistory();
                    return;
                }
                ClientTimer::setTimeoutStatic(function () {
                    $this->pollLog();
                }, 1000);
            }, function () {
                // A failed poll is not a failed deploy — back off and keep watching.
                ClientTimer::setTimeoutStatic(function () {
                    $this->pollLog();
                }, 3000);
            });
    }

    /**
     * The task writes an explicit end marker; the log is the only signal the page has.
     * strpos, not str_contains — the latter does not transpile.
     */
    public function isFinished(): bool
    {
        return strpos($this->log, '[done]') !== false || strpos($this->log, '[failed]') !== false;
    }

    public function scrollLog()
    {
        <<<'javascript'
        setTimeout(() => {
            const el = document.getElementById('deploy-terminal');
            if (el) { el.scrollTop = el.scrollHeight; }
        }, 10);
        javascript;
    }

    // ---------------------------------------------------------------- stored logs

    public function openHistory($record)
    {
        // Flattened to a scalar here: indexing a component PROPERTY in a template compiles to a
        // broken htmlentities() call (indexing a loop variable is fine).
        $this->openTitle = '#' . $record['Id'] . ' · ' . $record['ServerName']
            . ' · ' . $this->formatTime($record['StartedOn']);
        $this->showRecord = true;
        $this->openLog = '';
        $this->http->get("/api/admin/deploy/history/{$record['Id']}")
            ->then(function ($data) {
                $this->openLog = $data['Logs'] ? $data['Logs'] : '(no output was recorded)';
            }, function () {
                $this->openLog = '(the log could not be read)';
            });
    }

    public function closeHistory()
    {
        $this->showRecord = false;
        $this->openTitle = '';
        $this->openLog = '';
    }

    // ---------------------------------------------------------------- display helpers

    public function shortSha(?string $sha): string
    {
        return $sha ? substr($sha, 0, 12) : '—';
    }

    public function formatTime(?int $microseconds): string
    {
        if (!$microseconds) {
            return '—';
        }
        return gmdate('Y-m-d H:i:s', (int) ($microseconds / 1000000)) . ' UTC';
    }

    public function duration($row): string
    {
        if (!$row['StartedOn'] || !$row['CompletedOn']) {
            return '—';
        }
        return (int) (($row['CompletedOn'] - $row['StartedOn']) / 1000000) . 's';
    }

    /** Spelled out — a concatenated class name is invisible to the CSS tree-shaker. */
    public function outcomeClass($row): string
    {
        if ($row['IsSuccessful'] === true) {
            return 'badge text-bg-success';
        }
        if ($row['IsSuccessful'] === false) {
            return 'badge text-bg-danger';
        }
        return 'badge text-bg-secondary';
    }

    public function outcomeText($row): string
    {
        if ($row['IsSuccessful'] === true) {
            return $row['Kind'] === 'check' ? 'ready' : 'ok';
        }
        if ($row['IsSuccessful'] === false) {
            return $row['Kind'] === 'check' ? 'not ready' : 'failed';
        }
        return 'running';
    }

    public function slotMove($row): string
    {
        if (!$row['ToSlot']) {
            return '—';
        }
        return ($row['FromSlot'] ? $row['FromSlot'] : 'none') . ' → ' . $row['ToSlot'];
    }
}
