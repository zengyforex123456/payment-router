<?php

declare(strict_types=1);

namespace Converge\Security;

/**
 * BotRateLimiter — Redis Lua 原子点击频率计数器 (多容器共享, 替代进程内 $clickWindow)
 *
 * Key: bot:clicks:{ip} (Sorted Set), TTL 120s.
 * 降级: Redis 不可用 → isAvailable()=false → 调用方回退进程内计数。
 */
class BotRateLimiter
{
    private ?\Predis\Client $redis;
    private bool $available = false;
    private string $prefix;
    private int $ttlSeconds;

    /** @param \Predis\Client|null $redis 已连接客户端 (可选) */
    public function __construct(
        ?\Predis\Client $redis = null,
        ?array $config = null,
        float $timeout = 1.0,
    ) {
        $cfg = $config ?? $this->loadConfig();
        $this->prefix = $cfg['bot_rate_limiter']['prefix'] ?? 'bot:clicks:';
        $this->ttlSeconds = (int)($cfg['bot_rate_limiter']['ttl_seconds'] ?? 120);

        if ($redis !== null) { $this->redis = $redis; $this->available = true; return; }

        try {
            $this->redis = new \Predis\Client([
                'scheme'  => 'tcp',
                'host'    => $cfg['host'] ?? '127.0.0.1',
                'port'    => (int)($cfg['port'] ?? 6379),
                'timeout' => $timeout,
            ], ['prefix' => null]);
            $this->redis->connect();
            $this->available = true;
        } catch (\Throwable $e) {
            error_log('BotRateLimiter: Redis unavailable — ' . $e->getMessage());
        }
    }

    // ═══ Public API ═══

    /** 记录点击 + 返回窗口内总数 (Lua EVAL 原子操作) */
    public function recordClick(string $ip, ?float $microtime = null, ?string $clickId = null): int
    {
        if (!$this->available || $ip === '') return 0;

        $now = $microtime ?? microtime(true);
        $id = $clickId ?? bin2hex(random_bytes(8));

        $lua = <<<'LUA'
            local k = KEYS[1]
            redis.call('ZREMRANGEBYSCORE', k, 0, tonumber(ARGV[1]))
            redis.call('ZADD', k, tonumber(ARGV[2]), ARGV[3])
            redis.call('EXPIRE', k, tonumber(ARGV[4]))
            return redis.call('ZCARD', k)
        LUA;

        try {
            return (int)$this->redis->eval($lua, 1,
                $this->prefix . $ip, $now - 120, (string)$now, $id, (string)$this->ttlSeconds);
        } catch (\Throwable $e) {
            $this->markUnavailable('recordClick: ' . $e->getMessage());
            return 0;
        }
    }

    /** 只读计数 (不记录新点击) */
    public function countRecent(string $ip, int $windowSec = 60): int
    {
        if (!$this->available || $ip === '') return 0;
        $k = $this->prefix . $ip;
        try {
            $this->redis->zremrangebyscore($k, 0, microtime(true) - $windowSec);
            return (int)$this->redis->zcard($k);
        } catch (\Throwable $e) { $this->markUnavailable('count: ' . $e->getMessage()); return 0; }
    }

    /** L2 评分: 与 BotDetector::checkClickFrequency() 相同格式 */
    public function getScore(string $ip, int $maxPerWindow = 20, int $windowSec = 60): array
    {
        if (!$this->available || $ip === '') return ['score' => 0, 'reason' => ''];

        try {
            $k = $this->prefix . $ip;
            $now = microtime(true);
            $this->redis->zremrangebyscore($k, 0, $now - $windowSec);
            $n = (int)$this->redis->zcard($k);

            if ($n > $maxPerWindow * 3) return ['score' => 80, 'reason' => "L2(Redis): {$n} clicks in {$windowSec}s (3x)"];
            if ($n > $maxPerWindow * 2) return ['score' => 50, 'reason' => "L2(Redis): {$n} clicks in {$windowSec}s (2x)"];
            if ($n > $maxPerWindow)     return ['score' => 25, 'reason' => "L2(Redis): {$n} clicks in {$windowSec}s"];
            return ['score' => 0, 'reason' => ''];
        } catch (\Throwable $e) { $this->markUnavailable('score: ' . $e->getMessage()); return ['score' => 0, 'reason' => '']; }
    }

    public function isAvailable(): bool { return $this->available; }

    /** @return array{available: bool, prefix: string, ttl: int} */
    public function getStats(): array
    {
        return ['available' => $this->available, 'prefix' => $this->prefix, 'ttl' => $this->ttlSeconds];
    }

    // ═══ Private ═══

    private function markUnavailable(string $reason): void
    { $this->available = false; error_log('BotRateLimiter degraded: ' . $reason); }

    /** @return array */
    private function loadConfig(): array
    {
        $p = dirname(__DIR__, 2) . '/config/redis.php';
        return is_file($p) ? require $p : [];
    }
}
