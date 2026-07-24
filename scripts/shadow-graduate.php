<?php
/**
 * shadow-graduate.php — ApiRegistry placeholder → ShadowMode 影子验证
 *
 * 每个 ApiRegistry placeholder action 自动注册到 ShadowMode。
 * ≥3 周期无回归 → 可毕业 → 手动 graduate() 激活。
 *
 * 用法:
 *   php scripts/shadow-graduate.php             查看所有影子功能状态
 *   php scripts/shadow-graduate.php --register  注册 ApiRegistry placeholders
 *   php scripts/shadow-graduate.php --graduate=analytics.export-report  毕业
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../kernel/src/Foundation/Database/db.php';

use Converge\Foundation\Contract\ApiRegistry;
use Converge\Foundation\Resilience\ShadowMode;

$db = db()->raw();
$sm = new ShadowMode($db);

$action = $argv[1] ?? 'status';
$arg   = $argv[2] ?? null;

// ═══ --register: 将 ApiRegistry placeholders 注册到 ShadowMode ═══
if ($action === '--register') {
    $all = ApiRegistry::getAllActions();
    $registered = 0;

    foreach ($all as $key => $action) {
        if ($action['status'] !== 'placeholder') continue;

        $existing = $sm->get($key);
        if ($existing) continue; // 已注册

        $sm->register($key, [
            'description' => $action['label'],
            'endpoint'    => $action['endpoint'],
            'owner'       => 'api-registry',
            'expected_impact' => '新 API 端点',
        ]);
        echo "  ✅ 注册: {$key} → shadow_1\n";
        $registered++;
    }

    echo "\n注册完成: {$registered} 个新影子功能\n";
}

// ═══ --graduate=key: 毕业某个影子功能 ═══
elseif (str_starts_with($action, '--graduate=')) {
    $key = substr($action, strlen('--graduate='));

    if ($sm->canGraduate($key)) {
        $sm->graduate($key);
        echo "✅ {$key} → active (已毕业)\n";
    } else {
        $feature = $sm->get($key);
        $phase = $feature['phase'] ?? 'unknown';
        $cycles = $feature['cycles_completed'] ?? 0;
        echo "❌ {$key} 不满足毕业条件 (phase={$phase}, cycles={$cycles}, need ≥3)\n";
        echo "   运行 recordCycle 推进, 然后重试\n";
    }
}

// ═══ --record=key: 记录一个影子周期 ═══
elseif (str_starts_with($action, '--record=')) {
    $key = substr($action, strlen('--record='));

    $feature = $sm->get($key);
    if (!$feature) {
        echo "❌ {$key} 未注册 — 先运行 --register\n";
        exit(1);
    }

    $passed = $sm->recordCycle($key, ['placeholder' => true], ['placeholder' => true]);
    $f = $sm->get($key);
    echo ($passed ? '✅' : '❌') . " {$key}: phase={$f['phase']}, cycles={$f['cycles_completed']}\n";

    if ($f['phase'] === ShadowMode::PHASE_GRADUATED) {
        echo "   🎓 可毕业! 运行: php scripts/shadow-graduate.php --graduate={$key}\n";
    }
}

// ═══ status (default): 列出所有影子功能 ═══
else {
    $stats = $sm->stats();
    $features = $sm->listAll();

    echo "ShadowMode Status — " . date('Y-m-d H:i:s') . "\n";
    echo str_repeat('─', 60) . "\n";
    printf("  Total: %d | Active: %d | In Shadow: %d | Graduated: %d\n\n",
        $stats['total'], $stats['active'], $stats['in_shadow'], $stats['graduated']
    );

    if (empty($features)) {
        echo "  (无影子功能 — 运行 --register 注册 ApiRegistry placeholders)\n";
    }

    foreach ($features as $f) {
        $icon = match ($f['phase']) {
            ShadowMode::PHASE_ACTIVE => '🟢',
            ShadowMode::PHASE_GRADUATED => '🎓',
            default => '🔵',
        };
        printf("  %s %-40s %-12s cycles=%d\n",
            $icon, $f['name'], $f['phase'], $f['cycles_completed']
        );
    }

    // 显示可毕业的
    $ready = array_filter($features, fn($f) => $f['phase'] === ShadowMode::PHASE_GRADUATED);
    if (!empty($ready)) {
        echo "\n🎓 可毕业:\n";
        foreach ($ready as $f) {
            echo "   php scripts/shadow-graduate.php --graduate={$f['name']}\n";
        }
    }
}
