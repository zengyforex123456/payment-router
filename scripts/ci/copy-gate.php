#!/usr/bin/env php
<?php
/**
 * copy-gate.php — 文案/落地页质量门禁
 *
 * 用法: php ci/copy-gate.php              # 检查所有页面
 *       php ci/copy-gate.php --page=xxx   # 检查单个页面
 *       php ci/copy-gate.php --json       # JSON 输出 (CI 集成)
 *
 * 退出码:
 *   0 = 全部通过 (所有页面 ≥55, 通过率 ≥60%)
 *   1 = 有阻断项 (部署被阻止)
 *   2 = 仅有警告 (可以部署, 但建议修复)
 *
 * 集成到 CI:
 *   - pre-commit hook
 *   - GitHub Actions
 *   - validate.sh (已集成)
 */

require_once APP_ROOT . '/app/CopyEvaluator/CopyScorer.php';
require_once APP_ROOT . '/app/CopyEvaluator/CopyPipeline.php';

use App\CopyEvaluator\CopyPipeline;

$jsonMode  = in_array('--json', $argv ?? []);
$singlePage = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--page=')) {
        $singlePage = substr($arg, 7);
    }
}

$pipeline = new CopyPipeline();

if ($singlePage) {
    $path = APP_ROOT . '/public/' . $singlePage;
    if (!file_exists($path)) {
        $path = APP_ROOT . '/resources/views/' . $singlePage;
    }
    if (!file_exists($path)) {
        echo json_encode(['error' => "Page not found: $singlePage"]) . "\n";
        exit(1);
    }

    $content = file_get_contents($path);
    $result  = $pipeline->scorePage($singlePage, $content);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($result['score'] >= 55 ? 0 : 1);
}

// Full gate check
$gate = $pipeline->gateCheck();

if ($jsonMode) {
    echo json_encode($gate, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "═══ Copy & Landing Page Quality Gate ═══\n\n";
    echo sprintf("Pages scored: %d | Pass rate: %d%% | Avg score: %d/100\n\n",
        $gate['report']['total'],
        $gate['report']['pass_rate'],
        $gate['report']['avg_score']
    );

    if (!empty($gate['blockers'])) {
        echo "❌ BLOCKERS (deploy blocked):\n";
        foreach ($gate['blockers'] as $b) {
            echo "  $b\n";
        }
        echo "\n";
    }

    if (!empty($gate['warnings'])) {
        echo "⚠️  WARNINGS (recommend fixing):\n";
        foreach ($gate['warnings'] as $w) {
            echo "  $w\n";
        }
        echo "\n";
    }

    if (empty($gate['blockers']) && empty($gate['warnings'])) {
        echo "✅ All pages pass quality gate.\n";
    }
}

if (!$gate['pass']) {
    exit(1); // Blockers exist
}

if (!empty($gate['warnings'])) {
    exit(2); // Warnings only
}

exit(0);
