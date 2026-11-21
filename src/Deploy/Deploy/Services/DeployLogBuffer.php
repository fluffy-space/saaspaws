<?php

namespace SaasPaws\Deploy\Deploy\Services;

use Fluffy\Data\Connector\RedisConnector;

/**
 * The live transcript of a running deploy, readable by offset.
 *
 * The deploy runs in a task worker; the page tailing it is served by a *different* worker, so
 * the log cannot be a return value or a property — it has to be somewhere both can see. Redis,
 * appended as the pipeline talks, read by `?offset=`. When the run finishes the whole thing is
 * written to `Deployment.Logs` in Postgres, which is the durable copy; this buffer is only the
 * window while it is in flight and expires on its own.
 *
 * APPEND is what makes the tail cheap: the reader asks "what is past byte N" instead of
 * re-fetching a growing blob every second.
 */
class DeployLogBuffer
{
    /** Long enough to read the log after a run ends, short enough to not accumulate. */
    private const TTL_SECONDS = 7200;

    /** A pathological build could log megabytes; cap what we keep in RAM. */
    private const MAX_BYTES = 2_000_000;

    public function __construct(private RedisConnector $redis) {}

    public function append(int $deploymentId, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }
        $key = $this->key($deploymentId);
        $redis = $this->redis->get();
        if ($redis->strlen($key) < self::MAX_BYTES) {
            $redis->append($key, $chunk);
        }
        // Refresh on every write so a long deploy cannot expire mid-run.
        $redis->expire($key, self::TTL_SECONDS);
    }

    /**
     * Everything past $offset.
     *
     * @return array{chunk: string, offset: int} the new offset to pass next time
     */
    public function read(int $deploymentId, int $offset = 0): array
    {
        $redis = $this->redis->get();
        $key = $this->key($deploymentId);
        $length = (int) $redis->strlen($key);
        if ($offset >= $length) {
            return ['chunk' => '', 'offset' => $length];
        }
        // GETRANGE is inclusive on both ends.
        $chunk = $redis->getRange($key, $offset, $length - 1);
        return ['chunk' => is_string($chunk) ? $chunk : '', 'offset' => $length];
    }

    public function clear(int $deploymentId): void
    {
        $this->redis->get()->del($this->key($deploymentId));
    }

    private function key(int $deploymentId): string
    {
        return "DP:Log:$deploymentId";
    }
}
