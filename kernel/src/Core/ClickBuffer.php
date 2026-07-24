<?php
/**
 * ClickBuffer — Redis-backed click write buffer (🩺 可自愈 + 🔭 可观察)
 *
 * 100万点击/天的高并发优化: Redis RPUSH (~0.1ms) → 不可用时降级 MySQL 直写.
 * ClickStore.php 在 write() 返回 false 时自动走 MySQL 直写路径.
 *
 * 用法:
 *   $buffer = new \Converge\Core\ClickBuffer($db);
 *   if ($buffer->write($data)) $buffer->incrementCounter($campaignId);
 *   // else: ClickStore handles MySQL fallback
 */
declare(strict_types=1);

namespace Converge\Core;

class ClickBuffer
{
    private \mysqli $db;
    private ?\Predis\Client $redis = null;
    private array $config;
    private bool $degraded = false;
    private float $lastRetryTime = 0;
    private int $retryInterval;
    private string $queueKey;
    private string $counterKey;
    private string $degradedKey;

    public function __construct(\mysqli $db, ?array $redisConfig = null)
    {
        $this->db = $db;
        $configFile = __DIR__ . '/../../config/redis.php';
        $this->config = $redisConfig ?? (file_exists($configFile) ? require $configFile : [
            'host' => '127.0.0.1', 'port' => 6379, 'timeout' => 2.0,
            'retry_interval' => 30, 'queue_key' => 'clicks:buffer',
            'counter_key' => 'clicks:realtime', 'degraded_key' => 'clicks:degraded',
        ]);
        $this->queueKey    = $this->config['queue_key'] ?? 'clicks:buffer';
        $this->counterKey  = $this->config['counter_key'] ?? 'clicks:realtime';
        $this->degradedKey = $this->config['degraded_key'] ?? 'clicks:degraded';
        $this->retryInterval = (int)($this->config['retry_interval'] ?? 30);
        $this->connectRedis();
    }

    /** Write click to Redis buffer. Returns false → caller uses MySQL fallback. */
    public function write(array $data): bool
    {
        if ($this->degraded && $this->shouldRetryRedis()) {
            $this->connectRedis();
        }
        if ($this->redis !== null && !$this->degraded) {
            try {
                // Redis wire format (not HTML — AlpineHelper.encode only needed for browser output)
                $payload = json_encode($data, JSON_UNESCAPED_UNICODE); /* AlpineHelper omit: Redis */
                $this->redis->rpush($this->queueKey, [$payload]);
                return true;
            } catch (\Throwable $e) {
                $this->markDegraded('Redis RPUSH failed: ' . $e->getMessage());
            }
        }
        return false;
    }

    /** Increment real-time click counter. Returns 0 in degraded mode. */
    public function incrementCounter(int $campaignId): int
    {
        if ($this->redis !== null && !$this->degraded) {
            try {
                $key = $this->counterKey . ':' . $campaignId;
                $count = (int)$this->redis->incr($key);
                $this->redis->expire($key, 3600);
                return $count;
            } catch (\Throwable) {}
        }
        return 0;
    }

    public function isDegraded(): bool
    {
        return $this->degraded || $this->redis === null;
    }

    /** Health status: redis ('ok'|'degraded'), queue_length, degraded. */
    public function health(): array
    {
        $queueLen = 0;
        $redisOk = 'degraded';
        if ($this->redis !== null && !$this->degraded) {
            try {
                $queueLen = (int)$this->redis->llen($this->queueKey);
                $this->redis->ping();
                $redisOk = 'ok';
            } catch (\Throwable) {}
        }
        return [
            'redis'        => $redisOk,
            'degraded'     => $this->degraded,
            'queue_length' => $queueLen,
            'message'      => $this->degraded
                ? 'Degraded: MySQL direct. Retry in ' . max(0, $this->retryInterval - (int)(microtime(true) - $this->lastRetryTime)) . 's'
                : 'Healthy: Redis buffering active',
        ];
    }

    // ═══ Internal ═══

    private function connectRedis(): void
    {
        try {
            if (!class_exists('Predis\Client')) {
                $this->markDegraded('Predis not installed');
                return;
            }
            $this->redis = new \Predis\Client([
                'scheme' => 'tcp',
                'host'   => $this->config['host'] ?? '127.0.0.1',
                'port'   => (int)($this->config['port'] ?? 6379),
                'timeout' => (float)($this->config['timeout'] ?? 2.0),
            ]);
            $this->redis->ping();
            $this->degraded = false;
            try { $this->redis->del([$this->degradedKey]); } catch (\Throwable) {}
        } catch (\Throwable $e) {
            $this->redis = null;
            $this->markDegraded('Redis connection failed: ' . $e->getMessage());
        }
    }

    private function markDegraded(string $reason): void
    {
        if (!$this->degraded) {
            $this->degraded = true;
            $this->lastRetryTime = microtime(true);
            error_log('[ClickBuffer] Degraded: ' . $reason);
        }
    }

    private function shouldRetryRedis(): bool
    {
        return (microtime(true) - $this->lastRetryTime) >= $this->retryInterval;
    }
}
