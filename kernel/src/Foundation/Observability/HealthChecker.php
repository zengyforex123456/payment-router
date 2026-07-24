<?php

declare(strict_types=1);

namespace Converge\Foundation\Observability;

use mysqli;
use Converge\GeoIP\Infrastructure\GeoResolver;
use Converge\Installer\InstallStateDetector;

/**
 * HealthChecker — 🔭 可观察
 *
 * GET /health endpoint handler.
 * Checks: MySQL connection, GeoIP availability, disk space.
 * Returns JSON with {ok, uptime_seconds, checks, version}.
 */
class HealthChecker
{
    private ?mysqli $db = null;
    private ?GeoResolver $geoResolver = null;
    private float $startTime;
    private string $version;

    public function __construct(
        ?mysqli $db = null,
        ?GeoResolver $geoResolver = null,
        string $version = '1.1.5',
        ?float $startTime = null
    ) {
        $this->db = $db;
        $this->geoResolver = $geoResolver;
        $this->version = $version;
        $this->startTime = $startTime ?? (defined('APP_START_TIME') ? (float)APP_START_TIME : microtime(true));
    }

    /**
     * Run all health checks and output JSON response.
     */
    public function handle(): void
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis'    => $this->checkRedis(),
            'geoip'    => $this->checkGeoIp(),
            'disk'     => $this->checkDisk(),
        ];

        $allOk = true;
        foreach ($checks as $check) {
            if (!$check['ok']) {
                $allOk = false;
                break;
            }
        }

        $response = [
            'ok' => $allOk,
            'uptime_seconds' => (int)(microtime(true) - $this->startTime),
            'version' => $this->version,
            'timestamp' => date('c'),
            'checks' => $checks,
        ];

        $statusCode = $allOk ? 200 : 503;
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        // Don't kill the process under test — let PHPUnit continue
        if (!defined('PHPUNIT_RUNNING')) {
            exit;
        }
    }

    /**
     * Check MySQL database connectivity.
     */
    /**
     * Critical tables that must exist for the app to function.
     * If any of these are missing → schema not initialized → auto-heal should trigger.
     */
    private const CRITICAL_TABLES = ['settings', 'users', 'campaigns', 'clicks', 'migrations'];

    private function checkDatabase(): array
    {
        if ($this->db === null) {
            return ['ok' => false, 'error' => 'No database connection provided'];
        }

        $start = microtime(true);

        // L1: MySQL 连接检查
        try {
            if (!$this->db->ping()) {
                $latencyMs = (int)((microtime(true) - $start) * 1000);
                return [
                    'ok' => false,
                    'latency_ms' => $latencyMs,
                    'error' => $this->db->error ?: 'Connection lost',
                ];
            }
        } catch (\Throwable $e) {
            $latencyMs = (int)((microtime(true) - $start) * 1000);
            return [
                'ok' => false,
                'latency_ms' => $latencyMs,
                'error' => $e->getMessage(),
            ];
        }

        $latencyMs = (int)((microtime(true) - $start) * 1000);

        // L2: Schema 存在性检查 (不只检查 MySQL ping)
        $missingTables = [];
        foreach (self::CRITICAL_TABLES as $table) {
            try {
                $result = $this->db->query("SHOW TABLES LIKE '{$table}'");
                if (!$result || $result->num_rows === 0) {
                    $missingTables[] = $table;
                }
            } catch (\Throwable $e) {
                $missingTables[] = $table;
            }
        }

        if (count($missingTables) > 0) {
            return [
                'ok' => false,
                'latency_ms' => $latencyMs,
                'host' => defined('DB_HOST') ? DB_HOST : 'unknown',
                'error' => 'Schema not initialized — missing tables: ' . implode(', ', $missingTables),
                'missing_tables' => $missingTables,
                'action' => 'run: php scripts/run-migrations.php',
            ];
        }

        // L3: 检查待执行迁移数
        $pendingMigrations = 0;
        try {
            $result = $this->db->query("SELECT COUNT(*) AS cnt FROM migrations");
            if ($result) {
                $row = $result->fetch_assoc();
                $appliedCount = (int)($row['cnt'] ?? 0);
                $expectedCount = (new \Converge\Installer\InstallStateDetector())->countMigrationFiles();
                $pendingMigrations = max(0, $expectedCount - $appliedCount);
            }
        } catch (\Throwable $e) {
            // migrations table may not exist yet — handled by missing_tables above
        }

        return [
            'ok' => true,
            'latency_ms' => $latencyMs,
            'host' => defined('DB_HOST') ? DB_HOST : 'unknown',
            'applied_migrations' => $pendingMigrations === 0 ? 'up-to-date' : null,
            'pending_migrations' => $pendingMigrations,
        ];
    }

    /**
     * Check Redis availability (ClickBuffer).
     */
    private function checkRedis(): array
    {
        try {
            if (!class_exists('Predis\Client')) {
                return ['ok' => false, 'error' => 'Predis not installed'];
            }
            $config = require APP_ROOT . '/config/redis.php';
            $redis = new \Predis\Client([
                'scheme' => 'tcp',
                'host'   => $config['host'],
                'port'   => $config['port'],
                'timeout' => 1.0,
            ]);
            $redis->connect();
            $start = microtime(true);
            $redis->ping();
            $latencyMs = (int)((microtime(true) - $start) * 1000);
            $queueLen = (int)$redis->llen($config['queue_key']);

            return [
                'ok' => true,
                'latency_ms' => $latencyMs,
                'buffer_queue_length' => $queueLen,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'buffer_queue_length' => 0,
            ];
        }
    }

    /**
     * Check GeoIP provider availability.
     */
    private function checkGeoIp(): array
    {
        if ($this->geoResolver === null) {
            return ['ok' => false, 'error' => 'No GeoResolver provided'];
        }

        try {
            $providers = $this->geoResolver->getAvailableProviders();
            $providerCount = count($providers);

            // Verify by resolving a known public IP
            $testResult = $this->geoResolver->resolve('8.8.8.8');
            $resolved = $testResult !== null && $testResult->country !== 'N/A';

            return [
                'ok' => $providerCount > 0 && $resolved,
                'providers_available' => $providerCount,
                'providers' => $providers,
                'can_resolve' => $resolved,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'providers_available' => 0,
            ];
        }
    }

    /**
     * Check disk space on the storage directory.
     */
    private function checkDisk(): array
    {
        $path = defined('STORAGE_PATH') ? STORAGE_PATH : sys_get_temp_dir();

        try {
            $freeBytes = disk_free_space($path);
            $totalBytes = disk_total_space($path);

            if ($freeBytes === false || $totalBytes === false) {
                return ['ok' => false, 'error' => 'Cannot read disk space'];
            }

            $freeMb = (int)($freeBytes / 1024 / 1024);
            $totalMb = (int)($totalBytes / 1024 / 1024);
            $usedPercent = $totalMb > 0 ? (int)((($totalMb - $freeMb) / $totalMb) * 100) : 0;

            return [
                'ok' => $freeMb > 100, // Warning if less than 100MB free
                'free_mb' => $freeMb,
                'total_mb' => $totalMb,
                'used_percent' => $usedPercent,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Static helper: register a /health route handler.
     */
    public static function registerRoute(callable $getDb, ?callable $getGeo = null): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim($_SERVER['REQUEST_URI'] ?? '', '/') === 'health') {
            $db = $getDb();
            $geo = $getGeo ? $getGeo() : null;
            $checker = new self($db, $geo);
            $checker->handle();
        }
    }
}
