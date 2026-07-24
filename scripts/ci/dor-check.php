#!/usr/bin/env php
<?php
/**
 * dor-check.php — Definition of Ready 自动门禁
 *
 * 通过 data/requirements-data.php 读取需求，
 * 检查每条 completed 需求是否有 Given-When-Then。
 * 缺失 → exit(1) → 阻塞 PR 合并。
 *
 * 用法: php ci/dor-check.php [--json]
 * 退出码: 0 = DoR 通过, 1 = 有缺失项
 */

require_once APP_ROOT . '/data/requirements-data.php';

$failures = [];
$total = 0;
$jsonMode = in_array('--json', $argv ?? []);

foreach (REQUIREMENTS as $id => $req) {
    if (($req['status'] ?? '') !== 'completed') continue;

    $total++;
    $gwt = $req['gwt'] ?? [];
    $hasGiven = !empty($gwt['given'] ?? []);
    $hasWhen  = !empty($gwt['when'] ?? []);
    $hasThen  = !empty($gwt['then'] ?? []);

    if (!$hasGiven || !$hasWhen || !$hasThen) {
        $failures[] = [
            'id' => $id, 'desc' => $req['desc'] ?? '?',
            'missing' => ['given' => !$hasGiven, 'when' => !$hasWhen, 'then' => !$hasThen],
        ];
    }
}

if ($jsonMode) {
    echo json_encode([
        'total' => $total, 'pass' => $total - count($failures),
        'fail' => count($failures), 'failures' => $failures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    foreach ($failures as $f) {
        $missing = [];
        if ($f['missing']['given']) $missing[] = 'Given';
        if ($f['missing']['when'])  $missing[] = 'When';
        if ($f['missing']['then'])  $missing[] = 'Then';
        echo "❌ {$f['id']}: 缺少 " . implode('/', $missing) . " — {$f['desc']}\n";
    }
}

if (count($failures) > 0) {
    $pct = $total > 0 ? round(($total - count($failures)) / $total * 100) : 0;
    echo "\n🚫 DoR 不通过: " . count($failures) . "/{$total} 条需求缺少 GWT ({$pct}%)\n";
    echo "修复: 在 data/requirements-data.php 中补充 gwt 字段\n";
    exit(1);
}

echo "✅ DoR 通过: {$total}/{$total} 条需求已实例化\n";
exit(0);
