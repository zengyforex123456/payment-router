<?php

declare(strict_types=1);

namespace Converge\Security;

use Converge\Foundation\Observability\StructuredLogger;

/**
 * VisitCap — 🛡️ 访问频率限制 (对标 Binom Visit Cap)
 *
 * 限制同一用户(IP/Fingerprint)的重复访问，防止浪费预算。
 *
 * 限制维度:
 *   IP           — 同 IP 限制
 *   Fingerprint  — 浏览器指纹 (UA+IP hash)
 *   Cookie       — 浏览器 Cookie
 *
 * 限制类型:
 *   hourly       — 每小时上限
 *   daily        — 每天上限
 *   3days        — 3天上限
 *   campaign_total — Campaign 生命周期上限
 *
 * 存储: SQLite (memtable) — 不加重 MySQL 负担
 *
 * 用法:
 *   $cap = new VisitCap($pdo);
 *   $cap->configure('campaign:123', ['daily' => 5, 'hourly' => 3]);
 *   $verdict = $cap->check($ip, $ua, 'campaign:123');
 *   if ($verdict->isCapped) { redirect to blank; }
 */
class VisitCap
{
    private \PDO $pdo;
    private ?StructuredLogger $logger;

    /** @var array<string, array> 内存缓存窗口 */
    private array $window = [];

    public function __construct(
        ?\PDO $pdo = null,
        ?StructuredLogger $logger = null,
    ) {
        $this->logger = $logger;

        if ($pdo) {
            $this->pdo = $pdo;
        } else {
            $dbPath = defined('EVENTSTORE_PATH') ? EVENTSTORE_PATH : sys_get_temp_dir() . '/visitcap.db';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $this->pdo = new \PDO('sqlite:' . $dbPath);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        $this->initSchema();
    }

    private function initSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS visit_counter (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope TEXT NOT NULL,
                fingerprint TEXT NOT NULL,
                window_key TEXT NOT NULL,
                count INTEGER NOT NULL DEFAULT 1,
                first_seen TEXT NOT NULL DEFAULT (datetime('now')),
                last_seen TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(scope, fingerprint, window_key)
            );
            CREATE INDEX IF NOT EXISTS idx_vc_scope ON visit_counter(scope);
            CREATE INDEX IF NOT EXISTS idx_vc_lookup ON visit_counter(scope, fingerprint, window_key);
        ");
    }

    // ═══════════════════════════════════════
    // Config
    // ═══════════════════════════════════════

    /** @var array<string, array> scope → limits */
    private array $configs = [];

    /**
     * Configure visit cap for a scope.
     *
     * @param string $scope  "campaign:123" | "global"
     * @param array  $limits  ['hourly' => 5, 'daily' => 20, '3days' => 50, 'total' => 200]
     */
    public function configure(string $scope, array $limits): void
    {
        $this->configs[$scope] = $limits;
    }

    /**
     * Set default limits for all campaigns.
     */
    public function setDefaults(array $limits): void
    {
        $this->configure('global', $limits);
    }

    // ═══════════════════════════════════════
    // Check
    // ═══════════════════════════════════════

    /**
     * Check if this visit should be capped.
     *
     * @param string $ip    Visitor IP
     * @param string $ua    User-Agent
     * @param string $scope "campaign:123" | "global"
     * @return CapVerdict
     */
    public function check(string $ip, string $ua, string $scope): CapVerdict
    {
        $fingerprint = $this->fingerprint($ip, $ua);
        $limits = $this->configs[$scope] ?? $this->configs['global'] ?? [];

        if (empty($limits)) {
            return new CapVerdict(false, []);
        }

        $now = date('Y-m-d H:i:s');
        $exceeded = [];

        foreach ($limits as $period => $max) {
            $windowKey = $this->windowKey($period);
            $count = $this->increment($scope, $fingerprint, $windowKey, $now);

            if ($count > $max) {
                $exceeded[] = [
                    'period' => $period,
                    'count' => $count,
                    'max' => $max,
                ];
            }
        }

        $isCapped = !empty($exceeded);

        if ($isCapped) {
            $this->log('debug', "VisitCap: blocked {$fingerprint}", [
                'scope' => $scope,
                'exceeded' => $exceeded,
            ]);
        }

        return new CapVerdict($isCapped, $exceeded);
    }

    /**
     * Check + record. If not capped, increments the counter.
     * If capped, returns the verdict without incrementing further.
     *
     * Usage:
     *   $v = $cap->checkAndRecord($ip, $ua, 'campaign:123');
     *   if ($v->isCapped) { redirect blank; return; }
     *   // continue with normal tracking
     */
    public function checkAndRecord(string $ip, string $ua, string $scope): CapVerdict
    {
        $fingerprint = $this->fingerprint($ip, $ua);
        $limits = $this->configs[$scope] ?? $this->configs['global'] ?? [];

        if (empty($limits)) {
            return new CapVerdict(false, []);
        }

        $now = date('Y-m-d H:i:s');
        $exceeded = [];

        foreach ($limits as $period => $max) {
            $windowKey = $this->windowKey($period);

            // Check current count first (without incrementing)
            $currentCount = $this->getCount($scope, $fingerprint, $windowKey);

            if ($currentCount >= $max) {
                $exceeded[] = [
                    'period' => $period,
                    'count' => $currentCount,
                    'max' => $max,
                ];
            }
        }

        if (!empty($exceeded)) {
            return new CapVerdict(true, $exceeded);
        }

        // Not capped — increment all windows
        foreach ($limits as $period => $max) {
            $windowKey = $this->windowKey($period);
            $this->increment($scope, $fingerprint, $windowKey, $now);
        }

        return new CapVerdict(false, []);
    }

    // ═══════════════════════════════════════
    // Counters
    // ═══════════════════════════════════════

    private function increment(string $scope, string $fingerprint, string $windowKey, string $now): int
    {
        // Memory cache — avoids DB hit on every click
        $memKey = "{$scope}:{$fingerprint}:{$windowKey}";
        $this->window[$memKey] = ($this->window[$memKey] ?? 0) + 1;

        // DB write (fire-and-forget via INSERT OR REPLACE)
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO visit_counter (scope, fingerprint, window_key, count, first_seen, last_seen)
                 VALUES (:scope, :fp, :wk, 1, :now, :now)
                 ON CONFLICT(scope, fingerprint, window_key) DO UPDATE SET
                    count = count + 1,
                    last_seen = :now2"
            );
            $stmt->execute([
                ':scope' => $scope,
                ':fp' => $fingerprint,
                ':wk' => $windowKey,
                ':now' => $now,
                ':now2' => $now,
            ]);
        } catch (\Throwable $e) {
            // Non-critical — counter in memory still works
        }

        return $this->window[$memKey];
    }

    private function getCount(string $scope, string $fingerprint, string $windowKey): int
    {
        // Memory first
        $memKey = "{$scope}:{$fingerprint}:{$windowKey}";
        if (isset($this->window[$memKey])) {
            return $this->window[$memKey];
        }

        // DB fallback
        try {
            $stmt = $this->pdo->prepare(
                "SELECT count FROM visit_counter
                 WHERE scope = :scope AND fingerprint = :fp AND window_key = :wk"
            );
            $stmt->execute([
                ':scope' => $scope,
                ':fp' => $fingerprint,
                ':wk' => $windowKey,
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $count = $row ? (int)$row['count'] : 0;
            $this->window[$memKey] = $count;
            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ═══════════════════════════════════════
    // Maintenance
    // ═══════════════════════════════════════

    /**
     * Clean expired window counters.
     */
    public function cleanExpired(): int
    {
        $windows = [
            'hourly' => "-1 hour",
            'daily' => "-1 day",
            '3days' => "-3 days",
        ];

        $total = 0;
        foreach ($windows as $period => $cutoff) {
            $stmt = $this->pdo->prepare(
                "DELETE FROM visit_counter WHERE window_key LIKE :pattern AND last_seen < datetime('now', :cutoff)"
            );
            $stmt->execute([
                ':pattern' => "%{$period}%",
                ':cutoff' => $cutoff,
            ]);
            $total += $stmt->rowCount();
        }

        return $total;
    }

    /**
     * Reset counter for a specific scope+fingerprint.
     */
    public function reset(string $scope, string $fingerprint): void
    {
        $this->pdo->prepare("DELETE FROM visit_counter WHERE scope = :scope AND fingerprint = :fp")
            ->execute([':scope' => $scope, ':fp' => $fingerprint]);

        // Clear memory cache
        foreach ($this->window as $key => $val) {
            if (str_starts_with($key, "{$scope}:{$fingerprint}:")) {
                unset($this->window[$key]);
            }
        }
    }

    public function getStats(): array
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) as total, COUNT(DISTINCT fingerprint) as unique_fps FROM visit_counter");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'total_counters' => (int)($row['total'] ?? 0),
            'unique_visitors' => (int)($row['unique_fps'] ?? 0),
            'memory_cache_size' => count($this->window),
        ];
    }

    // ═══════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════

    private function fingerprint(string $ip, string $ua): string
    {
        return substr(md5($ip . '|' . substr($ua, 0, 100)), 0, 16);
    }

    private function windowKey(string $period): string
    {
        return match ($period) {
            'hourly' => date('Y-m-d-H'),
            'daily' => date('Y-m-d'),
            '3days' => '3d_' . floor(time() / 259200), // 3-day block number
            'total' => 'total',
            default => date('Y-m-d'),
        };
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}

class CapVerdict
{
    public function __construct(
        public readonly bool $isCapped,
        public readonly array $exceeded,
    ) {}

    public function toArray(): array
    {
        return [
            'is_capped' => $this->isCapped,
            'exceeded' => $this->exceeded,
        ];
    }
}
