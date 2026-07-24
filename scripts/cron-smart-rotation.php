<?php

/**
 * Smart Rotation Cron — 定时自动权重调整
 *
 * 建议频率: 每 15-30 分钟运行一次
 * Crontab (每15分钟): 0,15,30,45 * * * * php /path/to/scripts/cron-smart-rotation.php
 *
 * 用法:
 *   php scripts/cron-smart-rotation.php                    # 优化所有活跃 Campaign
 *   php scripts/cron-smart-rotation.php --campaign=123     # 优化指定 Campaign
 *   php scripts/cron-smart-rotation.php --shadow           # 影子模式 (只分析不更新)
 *   php scripts/cron-smart-rotation.php --dry-run          # 干跑 (输出调整建议)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use Converge\Evolution\SmartRotation;

// Parse CLI args
$opts = getopt('', ['campaign::', 'shadow', 'dry-run']);
$campaignId = isset($opts['campaign']) ? (int)$opts['campaign'] : null;
$shadowMode = isset($opts['shadow']);
$dryRun = isset($opts['dry-run']);

// Init
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$db->set_charset('utf8mb4');

$rotation = new SmartRotation($db, null, null);

// Log start
error_log('SmartRotation cron started: ' . json_encode([
    'campaign_id' => $campaignId,
    'shadow' => $shadowMode,
    'dry_run' => $dryRun,
]));

echo "╔══════════════════════════════════════╗\n";
echo "║   Smart Rotation Cron                ║\n";
echo "╚══════════════════════════════════════╝\n\n";

echo "Config:\n";
echo "  Min sample size: {$rotation->getConfig()['min_sample_size']} clicks\n";
echo "  Exploration reserve: " . ($rotation->getConfig()['exploration_reserve'] * 100) . "%\n";
echo "  Max weight shift: " . ($rotation->getConfig()['max_weight_shift'] * 100) . "%\n";
echo "  Performance window: {$rotation->getConfig()['performance_window_days']} days\n";

if ($shadowMode) echo "  ⚠️  SHADOW MODE — no changes will be applied\n";
if ($dryRun) echo "  🔍 DRY RUN — analysis only\n";

echo "\n";

// Execute
if ($campaignId) {
    $result = $rotation->optimize($campaignId);
    printResult($campaignId, $result);
} else {
    $results = $rotation->optimizeAll();
    $adjusted = 0;
    foreach ($results as $cid => $result) {
        if (isset($result['summary']) && $result['summary']['offers_adjusted'] > 0) {
            $adjusted++;
        }
        printResult($cid, $result);
    }
    echo "\n---\n";
    echo "Total campaigns: " . count($results) . "\n";
    echo "With adjustments: {$adjusted}\n";
}

error_log('SmartRotation cron completed');
$db->close();

// ═══════════════════════════════════════

function printResult(int $campaignId, array $result): void
{
    if (isset($result['error'])) {
        echo "Campaign #{$campaignId}: ❌ {$result['error']}\n";
        return;
    }

    $name = $result['campaign_name'] ?? 'unknown';
    $summary = $result['summary'] ?? [];
    $offerAdj = $summary['offers_adjusted'] ?? 0;
    $lpAdj = $summary['lps_adjusted'] ?? 0;

    if ($offerAdj === 0 && $lpAdj === 0) {
        echo "Campaign #{$campaignId} ({$name}): ✅ No changes needed\n";
        return;
    }

    echo "Campaign #{$campaignId} ({$name}): 🔄 Adjusted\n";

    foreach ($result['offers'] ?? [] as $oid => $data) {
        if (abs($data['change']) > 0.5) {
            $direction = $data['change'] > 0 ? '📈' : '📉';
            printf(
                "  {$direction} Offer #{$oid}: %5.1f%% → %5.1f%% (%+5.1f%%) | EPC=%.4f | %d clicks | %s\n",
                $data['old_weight'],
                $data['new_weight'],
                $data['change'],
                $data['epc'],
                $data['clicks'],
                $data['reason'],
            );
        }
    }

    foreach ($result['landing_pages'] ?? [] as $lid => $data) {
        if (abs($data['change']) > 0.5) {
            $direction = $data['change'] > 0 ? '📈' : '📉';
            printf(
                "  {$direction} LP #{$lid}: %5.1f%% → %5.1f%% (%+5.1f%%) | EPC=%.4f | %s\n",
                $data['old_weight'],
                $data['new_weight'],
                $data['change'],
                $data['epc'],
                $data['reason'],
            );
        }
    }

    echo "\n";
}
