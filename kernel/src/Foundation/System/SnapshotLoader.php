<?php

declare(strict_types=1);

namespace Converge\Foundation\System;

/**
 * SnapshotLoader — 快照加载器 (带三级降级链)
 *
 * 降级链: 快照文件 → Redis缓存 → 实时DB查询 → 默认空状态
 * 用户永远看到东西，永远不白屏。
 *
 * 用法:
 *   $loader = new SnapshotLoader();
 *   $data = $loader->load();     // dashboard data (always returns something)
 *   $version = $loader->version(); // current snapshot version
 */
class SnapshotLoader
{
    private string $snapshotDir;
    private ?array $loaded = null;
    private string $version = 'unknown';
    private string $lang;

    public function __construct(?string $dir = null, string $lang = 'zh')
    {
        $this->snapshotDir = $dir ?? (defined('STORAGE_PATH') ? STORAGE_PATH . '/snapshots' : sys_get_temp_dir() . '/snapshots');
        $this->lang = $lang;
    }

    /**
     * Load dashboard data with full degradation chain.
     * Never returns null — degrades gracefully.
     */
    public function loadDashboard(): array
    {
        // Level 1: Snapshot file (< 1ms)
        $data = $this->loadFromFile('dashboard');
        if ($data !== null) return $data;

        // Level 2: Redis cache (~2ms)
        $data = $this->loadFromRedis('dashboard');
        if ($data !== null) return $data;

        // Level 3: Live DB query (~50ms) — emergency fallback
        $data = $this->loadFromDB();
        if ($data !== null) return $data;

        // Level 4: Default empty state — never blank
        return $this->defaultDashboard();
    }

    /**
     * Load funnel data with degradation.
     */
    public function loadFunnel(): array
    {
        $snapshot = $this->loadFromFile('funnel');
        if ($snapshot !== null) return $snapshot;

        return [
            'ToFu_clicks' => 0, 'MoFu_lp_views' => 0, 'BoFu_conversions' => 0,
            'lp_rate' => 0, 'conv_rate' => 0,
        ];
    }

    /**
     * Load health data with degradation.
     */
    public function loadHealth(): array
    {
        $snapshot = $this->loadFromFile('health');
        if ($snapshot !== null) return $snapshot;

        return ['db' => false, 'eventstore' => false, 'events' => 0, 'disk_free_mb' => 0];
    }

    /** Get current snapshot version */
    public function version(): string
    {
        return $this->version;
    }

    // ═══════════════════════════════════════
    // Level 1: File snapshot (< 1ms)
    // ═══════════════════════════════════════

    private function loadFromFile(string $section): ?array
    {
        // Language as snapshot dimension: zh/dashboard-latest.json or en/dashboard-latest.json
        $file = $this->snapshotDir . '/' . $this->lang . '/dashboard-latest.json';
        // Fallback to root if language-specific not found
        if (!file_exists($file)) {
            $file = $this->snapshotDir . '/dashboard-latest.json';
        }
        if (!file_exists($file)) return null;

        try {
            $json = file_get_contents($file);
            if (!$json) return null;

            $snapshot = json_decode($json, true);
            if (!$snapshot) return null;

            $this->version = $snapshot['version'] ?? 'unknown';
            $data = $snapshot['data'] ?? null;
            if (!$data) return null;

            return $data[$section] ?? null;
        } catch (\Throwable $e) {
            error_log("[SnapshotLoader] File read failed: " . $e->getMessage());
            return null;
        }
    }

    // ═══════════════════════════════════════
    // Level 2: Redis cache (~2ms)
    // ═══════════════════════════════════════

    private function loadFromRedis(string $section): ?array
    {
        if (!class_exists('Redis')) return null;

        try {
            $redis = new \Redis();
            if (!$redis->connect('127.0.0.1', 6379, 0.5)) return null;

            $cached = $redis->get("converge:snapshot:{$section}");
            if ($cached === false) return null;

            $this->version = 'redis';

            $data = json_decode($cached, true);
            return $data ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ═══════════════════════════════════════
    // Level 3: Live DB (emergency)
    // ═══════════════════════════════════════

    private function loadFromDB(): ?array
    {
        try {
            $db = new \mysqli(
                defined('DB_HOST') ? DB_HOST : '127.0.0.1',
                defined('DB_USER') ? DB_USER : 'root',
                defined('DB_PASSWORD') ? DB_PASSWORD : '',
                defined('DB_NAME') ? DB_NAME : 'converge'
            );
            $clicks = (int)$db->query('SELECT COUNT(*) FROM clicks')->fetch_row()[0];
            $convs = (int)$db->query('SELECT COUNT(*) FROM conversions')->fetch_row()[0];
            $rev = (float)$db->query('SELECT COALESCE(SUM(payout),0) FROM conversions')->fetch_row()[0];
            $cost = (float)$db->query('SELECT COALESCE(SUM(cost),0) FROM clicks')->fetch_row()[0];

            $this->version = 'live';  // 实时数据，非快照

            return [
                'clicks' => $clicks, 'conversions' => $convs,
                'cr' => $clicks > 0 ? round($convs / $clicks * 100, 2) : 0,
                'roas' => $cost > 0 ? round($rev / $cost, 2) : 0,
                'revenue' => round($rev, 2), 'cost' => round($cost, 2),
                'profit' => round($rev - $cost, 2),
                'active_campaigns' => 0, 'offers' => 0, 'recent_campaigns' => [],
            ];
        } catch (\Throwable $e) {
            error_log("[SnapshotLoader] DB fallback failed: " . $e->getMessage());
            return null;
        }
    }

    // ═══════════════════════════════════════
    // Level 4: Default (never blank)
    // ═══════════════════════════════════════

    private function defaultDashboard(): array
    {
        $this->version = 'default';
        return [
            'clicks' => 0, 'conversions' => 0, 'cr' => 0, 'roas' => 0,
            'revenue' => 0, 'cost' => 0, 'profit' => 0,
            'active_campaigns' => 0, 'offers' => 0, 'recent_campaigns' => [],
        ];
    }
}
