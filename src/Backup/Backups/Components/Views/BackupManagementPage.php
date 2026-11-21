<?php

namespace SaasPaws\Backup\Backups\Components\Views;

use SaasPaws\Backup\Backups\Components\Models\BackupRunModel;
use Pupils\Components\Guards\HasCapability;
use Viewi\Components\Attributes\Middleware;
use Pupils\Components\Services\Dates\UserClock;
use Viewi\Components\BaseComponent;
use Viewi\Components\Http\HttpClient;
use Viewi\UI\Components\Tables\TableColumn;

/**
 * /admin/backups — backup history and on-demand runs (copied from Urlicer; design in its
 * docs/backup-plan.md §v2).
 *
 * The question this page exists to answer, above the fold, is "how old is the newest backup that
 * actually worked?" — the list is detail. Configuration lives on the settings page under group
 * `backup`; this is the operational view.
 *
 * Read-only list: no add/edit/delete, because a backup run is a record of something that happened,
 * not a row to author. The actions (back up, verify, files) sit above the list.
 */
#[Middleware([[HasCapability::class, 'ViewBackups']])]
class BackupManagementPage extends BaseComponent
{
    public array $columns = [];
    /**
     * Passed to ListPage, which watches it and refetches when it changes — the supported way to
     * make the list pick up a row created outside of it (a manual run).
     */
    public array $listQuery = [];
    public $status = null;
    public bool $busy = false;
    public string $error = '';
    public string $resultText = '';
    /** What is on the Storage Box, as opposed to what this instance remembers doing. */
    public $remote = null;
    public bool $remoteOpen = false;

    public function __construct(private HttpClient $http, private UserClock $clock) {}

    public function init()
    {
        $this->columns = [
            new TableColumn('Status'),
            new TableColumn('CreatedOn', 'When'),
            // One column, several lines. Seven narrow columns wrapped every value onto three
            // lines of its own and made the row unreadable; stacking them puts the artifact name
            // first and the rest as supporting detail.
            new TableColumn('Artifact', 'Details'),
        ];
        $this->loadStatus();
    }

    public function loadStatus()
    {
        $this->http->get('/api/admin/backup/status')
            ->then(function ($data) {
                $this->status = $data;
            });
    }

    /**
     * Runs synchronously server-side and can take a while — the whole point of the button is to
     * find out whether the destination really works, so it reports the actual outcome rather than
     * "started".
     */
    public function runNow()
    {
        $this->busy = true;
        $this->error = '';
        $this->http->post('/api/admin/backup/run')
            ->then(function ($data) {
                $this->busy = false;
                // `run` is a BackupRunModel object, not an array — array access on it is a fatal.
                if ($data['run'] && $data['run']->Status !== 'success') {
                    $this->error = $data['run']->Error ? $data['run']->Error : 'The backup failed.';
                }
                $this->loadStatus();
                // A NEW array, not a mutation: Viewi does not see in-place array writes, so
                // assigning is what actually fires ListPage's watcher.
                $this->listQuery = ['run' => $data['run'] ? $data['run']->Id : 0];
            }, function ($response) {
                $this->busy = false;
                $this->error = 'Request failed: ' . $response->body;
            });
    }

    /** Restoring a backup into a scratch database — the only thing that proves one is real. */
    public function verifyNow()
    {
        $this->busy = true;
        $this->error = '';
        $this->http->post('/api/admin/backup/verify')
            ->then(function ($data) {
                $this->busy = false;
                if ($data['run'] && $data['run']->Status !== 'success') {
                    $this->error = $data['run']->Error ? $data['run']->Error : 'The verify failed.';
                }
                $this->loadStatus();
                $this->listQuery = ['run' => $data['run'] ? $data['run']->Id : 0];
            }, function ($response) {
                $this->busy = false;
                $this->error = 'Request failed: ' . $response->body;
            });
    }

    /**
     * List the artifacts actually on the Storage Box. Separate from the run history because during
     * a recovery you are asking "what can I restore from" — and when this machine is the deployer
     * pointed at production, its own run history is the wrong answer entirely.
     */
    public function toggleRemote()
    {
        $this->remoteOpen = !$this->remoteOpen;
        if ($this->remoteOpen && !$this->remote) {
            $this->busy = true;
            $this->http->get('/api/admin/backup/remote')
                ->then(function ($data) {
                    $this->busy = false;
                    $this->remote = $data;
                }, function ($response) {
                    $this->busy = false;
                    $this->error = 'Could not list the destination: ' . $response->body;
                });
        }
    }

    public function remoteBaseLabel()
    {
        return $this->remote ? $this->remote['base'] : '';
    }

    /** Back up the uploaded files the database only references. */
    public function runFiles()
    {
        $this->busy = true;
        $this->error = '';
        $this->resultText = '';
        $this->http->post('/api/admin/backup/files')
            ->then(function ($data) {
                $this->busy = false;
                if ($data['run'] && $data['run']->Status !== 'success') {
                    $this->error = $data['run']->Error ? $data['run']->Error : 'The files backup failed.';
                } else {
                    $this->resultText = 'Files: ' . ($data['run'] ? $data['run']->Artifact : '');
                }
                $this->loadStatus();
                $this->listQuery = ['run' => $data['run'] ? $data['run']->Id : 0];
            }, function ($response) {
                $this->busy = false;
                $this->error = 'Request failed: ' . $response->body;
            });
    }

    /** True when the most recent backup ATTEMPT did not succeed — a different alarm to staleness. */
    public function lastRunFailed()
    {
        if (!$this->status || !$this->status['lastRun']) {
            return false;
        }
        return $this->status['lastRun']->Status === 'failed';
    }

    public function lastRunError()
    {
        if (!$this->status || !$this->status['lastRun']) {
            return '';
        }
        return $this->status['lastRun']->Error ? $this->status['lastRun']->Error : 'No error recorded.';
    }

    public function lastRunText()
    {
        if (!$this->status || !$this->status['lastRun']) {
            return '';
        }
        $minutes = $this->status['lastRunAgeMinutes'];
        if ($minutes < 60) {
            return $minutes . ' minutes ago';
        }
        if ($minutes < 2880) {
            return round($minutes / 60) . ' hours ago';
        }
        return round($minutes / 1440) . ' days ago';
    }

    public function hasEverVerified()
    {
        return $this->status && $this->status['lastVerify'];
    }

    public function verifyText()
    {
        if (!$this->status) {
            return '';
        }
        if (!$this->status['lastVerify']) {
            return 'never';
        }
        $minutes = $this->status['lastVerifyAgeMinutes'];
        if ($minutes < 60) {
            return $minutes . ' minutes ago';
        }
        if ($minutes < 2880) {
            return round($minutes / 60) . ' hours ago';
        }
        return round($minutes / 1440) . ' days ago';
    }

    public function verifyClass()
    {
        // 90 days: the plan calls for a quarterly drill, and this is that deadline made visible.
        if (!$this->status || !$this->status['lastVerify']) {
            return 'alert-warning';
        }
        if ($this->status['lastVerifyAgeMinutes'] > 129600) {
            return 'alert-warning';
        }
        return 'alert-success';
    }

    public function isVerify(BackupRunModel $run)
    {
        return $run->Kind === 'verify';
    }

    public function kindLabel(BackupRunModel $run)
    {
        if ($run->Kind === 'verify') {
            return 'Verify';
        }
        if ($run->Kind === 'files') {
            return 'Files';
        }
        return 'Backup';
    }

    public function hasEverSucceeded()
    {
        return $this->status && $this->status['lastSuccess'];
    }

    /**
     * Indexing a component PROPERTY from the template ({$status['lastSuccess']['Artifact']})
     * compiles to htmlentities() receiving the whole array — an opaque 500 with an empty response.
     * Only loop variables may be indexed, so scalars come out through a method instead.
     */
    public function lastArtifact()
    {
        if (!$this->status || !$this->status['lastSuccess']) {
            return '';
        }
        return $this->status['lastSuccess']->Artifact;
    }

    /** Null age means "never", which is a worse answer than "old" and is shown differently. */
    public function freshnessText()
    {
        if (!$this->status) {
            return '';
        }
        if (!$this->status['lastSuccess']) {
            return 'No successful backup has ever completed.';
        }
        $minutes = $this->status['lastSuccessAgeMinutes'];
        if ($minutes < 60) {
            return $minutes . ' minutes ago';
        }
        if ($minutes < 2880) {
            return round($minutes / 60) . ' hours ago';
        }
        return round($minutes / 1440) . ' days ago';
    }

    public function freshnessClass()
    {
        if (!$this->status || !$this->status['lastSuccess']) {
            return 'alert-danger';
        }
        // Two days without a successful backup is a problem regardless of the configured
        // schedule — the default is daily, and anything slower is a deliberate choice that
        // should still be visible.
        if ($this->status['lastSuccessAgeMinutes'] > 2880) {
            return 'alert-warning';
        }
        return 'alert-success';
    }

    public function fmtTime($micros)
    {
        if (!$micros) {
            return '—';
        }
        return $this->clock->date('Y-m-d H:i:s', (int)($micros / 1000000)) . $this->clock->suffix();
    }

    public function fmtDuration($ms)
    {
        if (!$ms) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms . ' ms';
        }
        return number_format($ms / 1000, 2) . ' s';
    }

    /** "44 tables" for a dump, "3 files" for the files tier: the same number means different things. */
    public function unitCount(BackupRunModel $run)
    {
        if ($run->TablesWithData < 1) {
            return '';
        }
        if ($run->Kind === 'files') {
            return $run->TablesWithData . ($run->TablesWithData === 1 ? ' file' : ' files');
        }
        return $run->TablesWithData . ($run->TablesWithData === 1 ? ' table' : ' tables');
    }

    /** Size · count · duration, joined only where there is something to say. */
    public function summaryLine(BackupRunModel $run)
    {
        $parts = [];
        if ($run->SizeBytes > 0) {
            $parts[] = $this->fmtSize($run->SizeBytes);
        }
        $count = $this->unitCount($run);
        if ($count !== '') {
            $parts[] = $count;
        }
        if ($run->DurationMs > 0) {
            $parts[] = $this->fmtDuration($run->DurationMs);
        }
        return implode(' · ', $parts);
    }

    public function fmtSize($bytes)
    {
        if (!$bytes) {
            return '—';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return number_format($bytes / 1048576, 1) . ' MB';
    }

    public function badgeText(BackupRunModel $run)
    {
        if ($run->Status === 'success') {
            return 'OK';
        }
        if ($run->Status === 'failed') {
            return 'Failed';
        }
        return 'Running';
    }

    public function badgeClass(BackupRunModel $run)
    {
        if ($run->Status === 'success') {
            return 'text-bg-success';
        }
        if ($run->Status === 'failed') {
            return 'text-bg-danger';
        }
        return 'text-bg-primary';
    }
}
