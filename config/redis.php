<?php

declare(strict_types=1);

/**
 * config/redis.php — Redis 连接配置
 *
 * 环境变量覆盖默认值:
 *   REDIS_HOST=127.0.0.1
 *   REDIS_PORT=6379
 *   REDIS_TIMEOUT=2.0
 *   REDIS_RETRY_INTERVAL=30
 */

return [
    'host'           => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port'           => (int)(getenv('REDIS_PORT') ?: 6379),
    'timeout'        => (float)(getenv('REDIS_TIMEOUT') ?: 2.0),
    'retry_interval' => (int)(getenv('REDIS_RETRY_INTERVAL') ?: 30),   // 降级后重试间隔(秒)
    'batch_size'     => (int)(getenv('REDIS_BATCH_SIZE') ?: 2000),     // 批量落盘每批最大条数
    'queue_key'      => 'clicks:buffer',
    'counter_key'    => 'clicks:realtime',
    'degraded_key'   => 'clicks:degraded',

    // Bot 频率检测 Redis sorted set 配置
    'bot_rate_limiter' => [
        'prefix'             => 'bot:clicks:',     // sorted set key 前缀 (per IP)
        'ttl_seconds'        => 120,               // 无活动 IP 的自动过期时间
        'connection_timeout' => 1.0,               // 连接超时 (秒)
    ],
];
