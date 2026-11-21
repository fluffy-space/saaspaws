<?php

namespace SaasPaws\Ops\Cron\Components\Views;

use Pupils\Components\Guards\HasCapability;
use Pupils\Components\Services\Dates\UserClock;
use Viewi\Components\BaseComponent;
use Viewi\Components\Attributes\Middleware;
use Viewi\Components\Http\HttpClient;

#[Middleware([[HasCapability::class, 'ViewCron']])]
class CronManagementPage extends BaseComponent
{
    public string $title = 'Cron Jobs';
    public $data = null;
    public bool $busy = false;

    public function __construct(private HttpClient $http, private UserClock $clock) {}

    public function init()
    {
        $this->load();
    }

    public function load()
    {
        $this->http->get('/api/admin/cron')
            ->then(function ($data) {
                $this->data = $data;
            });
    }

    public function pause()
    {
        $this->busy = true;
        $this->http->post('/api/admin/cron/pause')
            ->then(function () {
                $this->busy = false;
                $this->load();
            });
    }

    public function resume()
    {
        $this->busy = true;
        $this->http->post('/api/admin/cron/resume')
            ->then(function () {
                $this->busy = false;
                $this->load();
            });
    }

    public function isPaused()
    {
        return $this->data && $this->data['health']['paused'];
    }

    public function isStale()
    {
        return $this->data && $this->data['health']['stale'];
    }

    public function fmtTime($ts)
    {
        return $ts ? $this->clock->date('Y-m-d H:i:s', $ts) . $this->clock->suffix() : '—';
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

    public function heartbeatText()
    {
        if (!$this->data || !$this->data['health']['heartbeat']) {
            return 'no signal';
        }
        return (time() - $this->data['health']['heartbeat']) . 's ago';
    }

    public function badgeText($job)
    {
        if ($this->isPaused()) {
            return 'Paused';
        }
        if ($job['isStuck']) {
            return 'Stuck';
        }
        if ($job['isRunning']) {
            return 'Running';
        }
        if ($job['lastOk'] === -1) {
            return 'Never run';
        }
        if ($job['lastOk'] === 0) {
            return 'Failed';
        }
        return 'OK';
    }

    public function badgeClass($job)
    {
        if ($this->isPaused()) {
            return 'bg-secondary';
        }
        if ($job['isStuck'] || $job['lastOk'] === 0) {
            return 'bg-danger';
        }
        if ($job['isRunning']) {
            return 'bg-primary';
        }
        if ($job['lastOk'] === -1) {
            return 'bg-secondary';
        }
        return 'bg-success';
    }
}
