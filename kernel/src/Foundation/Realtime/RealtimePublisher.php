<?php
declare(strict_types=1);

namespace Converge\Foundation\Realtime;

/**
 * RealtimePublisher — 发布实时事件到 Redis
 *
 * 用法:
 *   RealtimePublisher::conversion([
 *       'campaign' => 'FB Retargeting',
 *       'amount' => '$45.00',
 *       'text' => '新转化',
 *   ]);
 */
class RealtimePublisher
{
    private const CHANNEL = 'converge:realtime';

    /**
     * 发布转化事件
     */
    public static function conversion(array $data): void
    {
        $payload = array_merge(['type' => 'conversion'], $data);
        self::publish($payload);
    }

    /**
     * 发布点击事件
     */
    public static function click(array $data): void
    {
        $payload = array_merge(['type' => 'click'], $data);
        self::publish($payload);
    }

    /**
     * 发布告警事件
     */
    public static function alert(array $data): void
    {
        $payload = array_merge(['type' => 'alert'], $data);
        self::publish($payload);
    }

    /**
     * 底层 Redis PUBLISH
     */
    private static function publish(array $data): void
    {
        try {
            $redis = self::connect();
            if (!$redis) return;

            $redis->publish(self::CHANNEL, json_encode($data, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // 实时推送失败不影响主业务
            error_log('[Realtime] publish failed: ' . $e->getMessage());
        }
    }

    private static function connect(): ?\Redis
    {
        static $redis = null;
        if ($redis !== null) return $redis ?: null;

        try {
            $host = $_ENV['REDIS_HOST'] ?? 'redis';
            $port = (int)($_ENV['REDIS_PORT'] ?? 6379);

            $redis = new \Redis();
            $redis->connect($host, $port, 0.5);
            return $redis;
        } catch (\Throwable $e) {
            error_log('[Realtime] Redis connect failed: ' . $e->getMessage());
            $redis = false;
            return null;
        }
    }
}
