<?php

declare(strict_types=1);

namespace Converge\Foundation\System;

/**
 * SnapshotGenerator — 数据快照生成器
 *
 * 定时任务每5分钟运行，生成所有 Dashboard 数据的 JSON 快照。
 * 数据库是"写者"，快照是"读者"。生产与展示彻底解耦。
 *
 * 运行: php scripts/cron-evolve.php snapshot
 */
class SnapshotGenerator
{
    private \mysqli $db;
    private string $snapshotDir;
    private int $retentionCount = 20; // Keep last 20 snapshots

    public function __construct(\mysqli $db, ?string $dir = null)
    {
        $this->db = $db;
        $this->snapshotDir = $dir ?? (defined('STORAGE_PATH') ? STORAGE_PATH . '/snapshots' : sys_get_temp_dir() . '/snapshots');
        if (!is_dir($this->snapshotDir)) {
            @mkdir($this->snapshotDir, 0755, true);
        }
    }

    /**
     * Generate all dashboard snapshots.
     */
    public function generate(): array
    {
        $version = date('Y-m-d-H-i-s');

        $data = [
            'version' => $version,
            'generated_at' => date('c'),
            'dashboard' => $this->dashboardData(),
            'funnel' => $this->funnelData(),
            'health' => $this->healthData(),
        ];

        // Generate per-language snapshots — language is a snapshot dimension
        $sizes = [];
        foreach (['zh', 'en'] as $lang) {
            $langData = $this->localize($data, $lang);
            $json = json_encode($langData, JSON_UNESCAPED_SLASHES);
            $checksum = hash('sha256', $json);

            $snapshot = [
                'version' => $version,
                'lang' => $lang,
                'checksum' => $checksum,
                'generated_at' => date('c'),
                'data' => $langData,
            ];

            // Write per-language latest
            $langDir = $this->snapshotDir . '/' . $lang;
            @mkdir($langDir, 0755, true);
            $latestFile = $langDir . '/dashboard-latest.json';
            file_put_contents($latestFile . '.tmp', json_encode($snapshot, JSON_UNESCAPED_SLASHES), LOCK_EX);
            rename($latestFile . '.tmp', $latestFile);

            // Archive per language
            $archiveDir = $this->snapshotDir . '/' . $lang . '/archive';
            @mkdir($archiveDir, 0755, true);
            copy($latestFile, $archiveDir . '/dashboard-' . $version . '.json');

            $sizes[$lang] = strlen($json);
        }

        // Clean old snapshots
        $this->cleanOldSnapshots();

        return [
            'version' => $version,
            'checksum' => substr($checksum ?? '', 0, 12),
            'sizes' => $sizes,
            'file' => $this->snapshotDir . '/zh/dashboard-latest.json',
        ];
    }

    /** Localize data for a specific language */
    private function localize(array $data, string $lang): array
    {
        if ($lang === 'en') return $data; // Raw data is already English

        // For zh: translate label fields
        $map = [
            'Clicks' => '点击', 'Conversions' => '转化', 'Revenue' => '收入',
            'Cost' => '成本', 'Profit' => '利润', 'Active' => '运行中',
            'Paused' => '已暂停', 'Archived' => '已归档',
            'Dashboard' => '工作台', 'Funnel' => '漏斗',
        ];

        // Deep translate the data array
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        foreach ($map as $en => $zh) {
            $json = str_replace('"' . $en . '"', '"' . $zh . '"', $json);
        }
        return json_decode($json, true) ?: $data;
    }

    private function dashboardData(): array
    {
        $clicks = (int)$this->db->query('SELECT COUNT(*) FROM clicks')->fetch_row()[0];
        $convs = (int)$this->db->query('SELECT COUNT(*) FROM conversions')->fetch_row()[0];
        $activeCampaigns = (int)$this->db->query("SELECT COUNT(*) FROM campaigns WHERE status='active'")->fetch_row()[0];
        $offers = (int)$this->db->query('SELECT COUNT(*) FROM offers')->fetch_row()[0];
        $rev = (float)$this->db->query('SELECT COALESCE(SUM(payout),0) FROM conversions')->fetch_row()[0];
        $cost = (float)$this->db->query('SELECT COALESCE(SUM(cost),0) FROM clicks')->fetch_row()[0];

        // Recent campaigns
        $recent = [];
        $r = $this->db->query('SELECT id, name, status, flow_type, created_at FROM campaigns ORDER BY created_at DESC LIMIT 5');
        while ($row = $r->fetch_assoc()) $recent[] = $row;

        return [
            'clicks' => $clicks,
            'conversions' => $convs,
            'cr' => $clicks > 0 ? round($convs / $clicks * 100, 2) : 0,
            'roas' => $cost > 0 ? round($rev / $cost, 2) : 0,
            'revenue' => round($rev, 2),
            'cost' => round($cost, 2),
            'profit' => round($rev - $cost, 2),
            'active_campaigns' => $activeCampaigns,
            'offers' => $offers,
            'recent_campaigns' => $recent,
        ];
    }

    private function funnelData(): array
    {
        $total = (int)$this->db->query('SELECT COUNT(*) FROM clicks')->fetch_row()[0];
        $lp = (int)$this->db->query('SELECT COUNT(*) FROM clicks WHERE lp_click=1')->fetch_row()[0];
        $conv = (int)$this->db->query('SELECT COUNT(*) FROM conversions')->fetch_row()[0];

        return [
            'ToFu_clicks' => $total,
            'MoFu_lp_views' => $lp,
            'BoFu_conversions' => $conv,
            'lp_rate' => $total > 0 ? round($lp / $total * 100, 1) : 0,
            'conv_rate' => $total > 0 ? round($conv / $total * 100, 2) : 0,
        ];
    }

    private function healthData(): array
    {
        $es = new \Converge\Traceability\EventStore();
        return [
            'db' => $this->db->ping(),
            'eventstore' => $es->isHealthy(),
            'events' => $es->count(),
            'disk_free_mb' => (int)(disk_free_space($this->snapshotDir) / 1024 / 1024),
        ];
    }

    private function cleanOldSnapshots(): void
    {
        $archiveDir = $this->snapshotDir . '/archive';
        if (!is_dir($archiveDir)) return;

        $files = glob($archiveDir . '/dashboard-*.json');
        if (count($files) <= $this->retentionCount) return;

        // Sort by name (which includes timestamp), keep newest
        rsort($files);
        $toDelete = array_slice($files, $this->retentionCount);
        foreach ($toDelete as $f) @unlink($f);
    }
}
