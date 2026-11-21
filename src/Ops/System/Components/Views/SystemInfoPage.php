<?php

namespace SaasPaws\Ops\System\Components\Views;

use Pupils\Components\Guards\HasCapability;
use Viewi\Components\BaseComponent;
use Viewi\Components\Attributes\Middleware;
use Viewi\Components\Http\HttpClient;

#[Middleware([[HasCapability::class, 'ViewSystemInfo']])]
class SystemInfoPage extends BaseComponent
{
    public string $title = 'System Information and Monitoring';
    public $systemInfo = null;
    public $processes = [];
    public string $sortKey = 'RAMKb';
    public bool $sortDesc = true;
    public function __construct(private HttpClient $http) {}

    public function init()
    {
        $this->http->get('/api/admin/system')
            ->then(function ($data) {
                $this->systemInfo = $data;
                $this->buildProcessList();
            });
    }

    function buildProcessList()
    {
        $list = [];
        if ($this->systemInfo) {
            foreach ($this->systemInfo['processes'] as $pid => $proc) {
                // Skip the `ps` header row artifact (CMD = "COMMAND").
                if ($proc['CMD'] === 'COMMAND') {
                    continue;
                }
                $list[] = $proc;
            }
        }
        $this->processes = $list;
        $this->sortProcesses();
    }

    function setSort($key)
    {
        if ($this->sortKey === $key) {
            $this->sortDesc = !$this->sortDesc;
        } else {
            $this->sortKey = $key;
            // Text sorts ascending by default, numbers descending (heaviest first).
            $this->sortDesc = $key !== 'CMD';
        }
        $this->sortProcesses();
    }

    function compareProc($a, $b, $key, $desc)
    {
        $av = $a[$key];
        $bv = $b[$key];
        $result = 0;
        if ($av < $bv) {
            $result = -1;
        } else if ($av > $bv) {
            $result = 1;
        }
        if ($desc) {
            $result = -$result;
        }
        return $result;
    }

    function sortProcesses()
    {
        // Build a NEW array reference — reassigning the same array object back
        // to $this->processes would not trip Viewi's proxy reactivity.
        $list = [];
        foreach ($this->processes as $proc) {
            $list[] = $proc;
        }
        $key = $this->sortKey;
        $desc = $this->sortDesc;
        $n = count($list);
        // Insertion sort — transpiler-safe and plenty fast for a process list.
        for ($i = 1; $i < $n; $i++) {
            $current = $list[$i];
            $j = $i - 1;
            while ($j >= 0 && $this->compareProc($list[$j], $current, $key, $desc) > 0) {
                $list[$j + 1] = $list[$j];
                $j--;
            }
            $list[$j + 1] = $current;
        }
        $this->processes = $list;
    }

    function sortIcon($key)
    {
        if ($this->sortKey !== $key) {
            return '';
        }
        return $this->sortDesc ? '▼' : '▲';
    }

    function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $maxUnit = 5;
        $currentUnit = 0;
        $currentValue = $bytes;
        while ($currentUnit < $maxUnit && $currentValue > 1024) {
            $currentUnit++;
            $currentValue = $currentValue / 1024;
        }

        return number_format($currentValue, $precision) . ' ' . $units[$currentUnit];
    }
}
