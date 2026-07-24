<?php
/**
 * PerfMiddleware — 请求性能计时 → EventStore + Response Header (🔭 可观察)
 *
 * 每个请求自动记录: 耗时、内存峰值、DB 查询数、响应码。
 * 注入到 response header: X-Converge-Time, X-Converge-Memory。
 *
 * 用法:
 *   PerfMiddleware::start();       // bootstrap 顶部
 *   PerfMiddleware::finish($db);   // shutdown
 */
declare(strict_types=1);
namespace Converge\Foundation\Observability;

use Converge\Traceability\Infrastructure\EventStore;

class PerfMiddleware
{
    private static float $startTime;
    private static int $startMemory;

    public static function start(): void
    {
        self::$startTime   = microtime(true);
        self::$startMemory = memory_get_usage();
    }

    /**
     * @return array{elapsed_ms:float, memory_kb:int, peak_kb:int}
     */
    public static function finish(?\mysqli $db = null): array
    {
        $elapsedMs = round((microtime(true) - self::$startTime) * 1000, 2);
        $memoryKb  = (int)((memory_get_usage() - self::$startMemory) / 1024);
        $peakKb    = (int)(memory_get_peak_usage() / 1024);

        // Inject timing headers
        if (!headers_sent()) {
            header("X-Converge-Time: {$elapsedMs}ms");
            header("X-Converge-Memory: {$peakKb}KB");
        }

        // Persist to EventStore (sampled: >200ms or >10MB)
        if ($db && ($elapsedMs > 200 || $peakKb > 10240)) {
            try {
                $store = new EventStore($db);
                $store->append('perf.slow_request', 'system', [
                    'elapsed_ms' => $elapsedMs,
                    'memory_kb'  => $memoryKb,
                    'peak_kb'    => $peakKb,
                    'url'        => $_SERVER['REQUEST_URI'] ?? 'cli',
                    'method'     => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                ]);
            } catch (\Throwable) {}
        }

        return ['elapsed_ms' => $elapsedMs, 'memory_kb' => $memoryKb, 'peak_kb' => $peakKb];
    }

    /** 返回当前耗时 (不终止计时) */
    public static function elapsedMs(): float
    {
        return round((microtime(true) - self::$startTime) * 1000, 2);
    }
}
