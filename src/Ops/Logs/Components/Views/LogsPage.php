<?php

namespace SaasPaws\Ops\Logs\Components\Views;

use Pupils\Components\Guards\HasCapability;
use Viewi\Components\BaseComponent;
use Viewi\Components\Attributes\Middleware;
use Viewi\Components\Http\HttpClient;

#[Middleware([[HasCapability::class, 'ViewSystemInfo']])]
class LogsPage extends BaseComponent
{
    public string $title = 'Application Logs';
    public $log = null;
    public array $lines = [];
    public array $units = [];       // configured source units: [{unit,label,color}]
    public bool $multiUnit = false; // true when more than one unit (blue/green) is configured
    public string $unitFilter = ''; // '' = merged (all units); else a single unit name
    public int $lineCount = 200;
    public string $priority = ''; // '' = all severities
    public bool $loading = false;

    // Paging state (journald cursors of the current page's boundaries).
    public string $firstCursor = '';
    public string $lastCursor = '';
    public bool $hasOlder = false;
    public bool $hasNewer = false;

    public function __construct(private HttpClient $http) {}

    public function init()
    {
        $this->reload();
    }

    /** Jump to the latest page (tail). */
    function reload()
    {
        $this->fetch('');
    }

    /** Previous (older) page — everything before the current oldest entry. */
    function older()
    {
        if ($this->hasOlder && $this->firstCursor !== '') {
            $this->fetch('&before=' . $this->firstCursor);
        }
    }

    /** Next (newer) page — everything after the current newest entry. */
    function newer()
    {
        if ($this->hasNewer && $this->lastCursor !== '') {
            $this->fetch('&after=' . $this->lastCursor);
        }
    }

    function fetch($pageParam)
    {
        $this->loading = true;
        $url = '/api/admin/logs?lines=' . $this->lineCount;
        if ($this->priority !== '') {
            $url = $url . '&priority=' . $this->priority;
        }
        if ($this->unitFilter !== '') {
            $url = $url . '&unit=' . $this->unitFilter;
        }
        $url = $url . $pageParam;
        $this->http->get($url)
            ->then(function ($data) {
                $this->log = $data;
                // The units list + multiUnit flag come back on every page (config-derived).
                if ($data) {
                    $this->units = $data['units'] ? $data['units'] : [];
                    $this->multiUnit = $data['multiUnit'];
                }
                // Rebuild the list property so Viewi's proxy reactivity re-renders the foreach.
                if ($data && $data['available']) {
                    $this->lines = $data['lines'];
                    $this->firstCursor = $data['firstCursor'] ? $data['firstCursor'] : '';
                    $this->lastCursor = $data['lastCursor'] ? $data['lastCursor'] : '';
                    $this->hasOlder = $data['hasOlder'];
                    $this->hasNewer = $data['hasNewer'];
                } else {
                    $this->lines = [];
                    $this->firstCursor = '';
                    $this->lastCursor = '';
                    $this->hasOlder = false;
                    $this->hasNewer = false;
                }
                $this->loading = false;
            });
    }

    function setPriority($p)
    {
        $this->priority = $p;
        $this->reload();
    }

    function setLines($n)
    {
        $this->lineCount = $n;
        $this->reload();
    }

    /** Narrow the merged stream to one source unit ('' = all). */
    function setUnit($u)
    {
        $this->unitFilter = $u;
        $this->reload();
    }

    function isAvailable()
    {
        return $this->log && $this->log['available'];
    }

    /**
     * Bootstrap badge class for a log line's source unit (blue/green tag).
     * Class names are spelled out in full (not concatenated) so Viewi's CSS
     * tree-shaking keeps them — a dynamically built `'bg-' . $color` would be
     * stripped from the bundle because it never appears as a literal.
     */
    function lineBadge($line)
    {
        switch ($line['color']) {
            case 'primary':
                return 'bg-primary';
            case 'success':
                return 'bg-success';
            case 'danger':
                return 'bg-danger';
            case 'warning':
                return 'bg-warning';
            case 'info':
                return 'bg-info';
            case 'light':
                return 'bg-light';
            case 'dark':
                return 'bg-dark';
            default:
                return 'bg-secondary';
        }
    }

    /**
     * Bootstrap outline-button class for a source-filter button.
     * Spelled out in full for the same tree-shaking reason as lineBadge().
     */
    function unitBtn($u)
    {
        switch ($u['color']) {
            case 'primary':
                return 'btn-outline-primary';
            case 'success':
                return 'btn-outline-success';
            case 'danger':
                return 'btn-outline-danger';
            case 'warning':
                return 'btn-outline-warning';
            case 'info':
                return 'btn-outline-info';
            case 'light':
                return 'btn-outline-light';
            case 'dark':
                return 'btn-outline-dark';
            default:
                return 'btn-outline-secondary';
        }
    }

    function errorText()
    {
        return $this->log ? $this->log['error'] : '';
    }
}
