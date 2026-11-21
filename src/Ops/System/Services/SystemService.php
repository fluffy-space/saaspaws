<?php

namespace SaasPaws\Ops\System\Services;

use Fluffy\Data\Connector\RedisConnector;
use Fluffy\Domain\Configuration\Config;
use Redis;

/**
 * Host and Redis figures for /admin/system, the resource alerts and the weekly report.
 *
 * One Redis: the app's own pool (config `redis`, Fluffy's RedisConnector). Its keyspace line is the
 * configured db index (`redis.dbIndex`), so on a Redis shared with another app the key counts are
 * this app's only; memory figures are the whole Redis instance's.
 */
class SystemService
{
    private int $redisDbIndex;

    function __construct(
        protected RedisConnector $redis,
        Config $config
    ) {
        $this->redisDbIndex = (int)($config->values['redis']['dbIndex'] ?? 0);
    }

    public function getOsInfo()
    {
        $data = file('/proc/meminfo');
        $meminfo = [];

        foreach ($data as $line) {
            // Split each line into key and value (e.g., "MemTotal: 16384064 kB")
            if (preg_match('/^(\w+):\s+(\d+)\s*(kB)?/i', $line, $matches)) {
                $key = $matches[1];
                $value = (int)($matches[2] * 1024);
                $meminfo[$key] = $value;
            }
        }

        $meminfo['free_percentage'] = $meminfo['MemAvailable'] * 100 / $meminfo['MemTotal'];
        $meminfo['used_percentage'] = 100 - $meminfo['free_percentage'];
        $cpuLoad = shell_exec("top -bn2 -d 0.01 | grep \"Cpu(s)\" | tail -n1 | awk -F ',' '{print 100 - $4}'");
        $diskSpaceRaw = shell_exec("df -h / | tail -n1");
        $diskSpace = preg_split("/\\s+/", $diskSpaceRaw);
        return [
            'free_percentage' => $meminfo['free_percentage'],
            'mem_available' => $meminfo['MemAvailable'],
            'mem_total' => $meminfo['MemTotal'],
            'mem_used' => $meminfo['MemTotal'] - $meminfo['MemAvailable'],
            'used_percentage' => $meminfo['used_percentage'],
            'cpu_load' => (float)$cpuLoad,
            'disk_space_free' => $diskSpace[3],
            'disk_space_used' => $diskSpace[2],
            'disk_space_used_precentage' => $diskSpace[4],
            'disk_space_total' => $diskSpace[1],
            'cpu_steal' => $this->cpuStealPercent(),
        ];
    }

    /**
     * Percentage of CPU time the hypervisor gave to OTHER tenants while we had work ready —
     * "steal". Meaningful only on a shared-vCPU host, which is exactly what we run on.
     *
     * This is the number that decides whether the box should move to dedicated vCPU. Without it
     * the choice is guesswork: high load average looks identical whether we are busy or merely
     * waiting for a core.
     *
     * `/proc/stat` counts jiffies since boot, so a single reading is a lifetime average and would
     * never show a problem that started this week. Two readings a moment apart give the current
     * rate instead. The sample is deliberately short — this runs inside a monitoring tick, and a
     * 100 ms window is enough to catch sustained steal, which is the only kind that matters.
     *
     * @return float steal as a percentage of total CPU time, 0.0 when unavailable (non-Linux,
     *               or a container without /proc/stat)
     */
    private function cpuStealPercent(): float
    {
        $first = $this->readCpuJiffies();
        if ($first === null) {
            return 0.0;
        }
        usleep(100000); // 100 ms
        $second = $this->readCpuJiffies();
        if ($second === null) {
            return 0.0;
        }
        $totalDelta = $second['total'] - $first['total'];
        if ($totalDelta <= 0) {
            return 0.0; // clock granularity — no ticks elapsed, nothing to report
        }
        return round(($second['steal'] - $first['steal']) * 100 / $totalDelta, 2);
    }

    /**
     * The aggregate `cpu` line of /proc/stat as [total, steal] jiffies.
     * Field 8 is steal; everything on the line sums to total CPU time.
     *
     * @return array{total: int, steal: int}|null
     */
    private function readCpuJiffies(): ?array
    {
        $line = @file('/proc/stat')[0] ?? null;
        if ($line === null || strncmp($line, 'cpu ', 4) !== 0) {
            return null;
        }
        $fields = preg_split('/\s+/', trim($line));
        array_shift($fields); // drop the "cpu" label
        if (count($fields) < 8) {
            return null; // kernel too old to report steal
        }
        return [
            'total' => (int) array_sum(array_map('intval', $fields)),
            'steal' => (int) $fields[7],
        ];
    }

    public function getProcessesInfo()
    {
        $processMap = [];
        $output = shell_exec("ps aux --sort=-%mem | awk '{print $2 \",\" $3 \",\" $4 \",\" $6 \",\" $11}'");
        // Convert string into an array of lines
        $processes = explode("\n", trim($output));
        foreach ($processes as $line) {
            $data = explode(",", $line);
            // $data[0] = PID, $data[2] = % MEM, $data[3] = RSS in KB
            $processMap[$data[0]] =
                [
                    'title' => "PID: {$data[0]} | CPU: {$data[1]}% | RAM: {$data[2]}% ({$data[3]} KB) | CMD: {$data[4]}",
                    'PID' => (int)$data[0],
                    'RAMPercentage' => (float)$data[2],
                    'RAMKb' => (float)$data[3],
                    'CMD' => $data[4],
                    'CPUPercentage' => (float)$data[1]
                ];
        }
        return $processMap;
    }

    private function getRedisInfo(Redis $redis)
    {
        $redisInfo = $redis->info('memory', 'keyspace');
        $redisInfo['used_percentage'] = $redisInfo['used_memory'] * 100 / $redisInfo['total_system_memory'];
        // Keyspace line of OUR db index ("keys=12,expires=3,avg_ttl=0"); absent while the db is empty.
        $keys = ['keys' => 0, 'expires' => 0, 'avg_ttl' => 0];
        $line = $redisInfo['db' . $this->redisDbIndex] ?? null;
        if (is_string($line)) {
            parse_str(str_replace(',', '&', $line), $keys);
        } elseif (is_array($line)) {
            $keys = $line + $keys;
        }
        // Fill against the instance's OWN cap, which is the limit that actually bites, not host
        // RAM: with noeviction, reaching maxmemory rejects writes while the host still looks half
        // idle. 0 when uncapped (maxmemory 0), since there is then no boundary to be near.
        $maxMemory = (int) ($redisInfo['maxmemory'] ?? 0);
        return [
            "db_index" => $this->redisDbIndex,
            "used_memory" => $redisInfo['used_memory'],
            "used_memory_human" => $redisInfo['used_memory_human'],
            "total_system_memory" => $redisInfo['total_system_memory'],
            "total_system_memory_human" => $redisInfo['total_system_memory_human'],
            "used_percentage" => $redisInfo['used_percentage'],
            "maxmemory" => $maxMemory,
            "maxmemory_percentage" => $maxMemory > 0
                ? round($redisInfo['used_memory'] * 100 / $maxMemory, 2)
                : 0.0,
            "keys" => (int)($keys['keys'] ?? 0),
            "keys_expires" => (int)($keys['expires'] ?? 0),
            "keys_avg_ttl" => (int)($keys['avg_ttl'] ?? 0),
        ];
    }

    public function getRedisInfoForApp()
    {
        return $this->getRedisInfo($this->redis->get());
    }
}
