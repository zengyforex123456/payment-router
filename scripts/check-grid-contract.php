<?php
/**
 * check-grid-contract.php — 网格合约检查
 *
 * 规则: 所有 .latte 模板中的 grid 容器必须使用 .grid(12列) + .col-*(明确占列)
 *       AI 偏离 → 提交阻塞
 *
 * 用法: php scripts/check-grid-contract.php          检查所有模板
 *       php scripts/check-grid-contract.php --staged 仅检查 staged
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$mode = in_array('--staged', $argv) ? 'staged' : 'all';
$violations = 0;

$files = $mode === 'staged'
    ? explode("\n", trim(shell_exec('git diff --cached --name-only --diff-filter=ACM 2>&1') ?: ''))
    : glob("$root/templates/**/*.latte");

$files = array_filter($files ?: [], fn($f) => str_ends_with((string)$f, '.latte'));

foreach ($files as $file) {
    $content = @file_get_contents(is_file($file) ? $file : "$root/$file");
    if (!$content) continue;

    $name = basename((string)$file);

    // 检查1: 使用裸 grid-template-columns 而不使用 .grid 类
    if (preg_match_all('/grid-template-columns:\s*repeat\(\d+/', $content, $m)) {
        $count = count($m[0]);
        echo "  ⚠ {$name}: {$count}处裸 grid-template-columns — 改用 class=\"grid\" + col-*\n";
        $violations += $count;
    }

    // 检查2: .grid 容器内子元素缺少 col-*
    if (preg_match('/class="[^"]*\bgrid\b[^"]*"/', $content)) {
        if (!preg_match('/col-\d+/', $content)) {
            echo "  ⚠ {$name}: .grid 容器存在, 但无 col-* 子元素\n";
            $violations++;
        }
    }

    // 检查3: 未使用令牌的固定间距
    if (preg_match_all('/gap:\s*\d+px/', $content, $m)) {
        echo "  ⚠ {$name}: " . count($m[0]) . "处固定px间距 — 改用 var(--grid-gap)\n";
        $violations += count($m[0]);
    }
}

if ($violations === 0) {
    echo "✅ 网格合约检查通过 — 所有模板使用 .grid(12列) + col-*\n";
    exit(0);
} else {
    echo "\n❌ {$violations} 网格合约违规\n";
    echo "   规则: 使用 class=\"grid\" + col-{1..12} 替代裸 grid-template-columns\n";
    echo "   间距: 使用 var(--grid-gap) 替代固定 px\n";
    exit(1);
}
