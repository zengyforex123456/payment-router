<?php
/**
 * verify-components.php — TDA 可验证门禁 G16: x-data 引用 → 注册表交叉验证
 *
 * 扫描所有 .latte 模板的 x-data= 属性, 提取组件名,
 * 与 ComponentRegistry 交叉验证: 每个引用必须在注册表中.
 *
 * 用法:
 *   php scripts/verify-components.php              # 全量检查
 *   php scripts/verify-components.php --staged     # 仅 staged 文件
 *
 * 退出码: 0 = 全部匹配, 1 = 有未注册组件引用
 */
declare(strict_types=1);

define('APP_ROOT', realpath(__DIR__ . '/..'));

require_once APP_ROOT . '/vendor/autoload.php';

use Converge\UI\Engine\ComponentRegistry;

$stagedOnly = in_array('--staged', $argv);
$errors     = [];
$totalRefs  = 0;

// ═══ 1. 收集模板文件 ═══
$files = [];
if ($stagedOnly) {
    exec('git diff --cached --name-only --diff-filter=ACM 2>&1', $staged);
    foreach ($staged as $f) {
        if (str_ends_with($f, '.latte')) {
            $files[] = APP_ROOT . '/' . $f;
        }
    }
} else {
    $files = glob(APP_ROOT . '/templates/**/*.latte');
}

if (empty($files)) {
    echo "🧩 G16 verify-components: 无文件需检查\n";
    exit(0);
}

// ═══ 2. 加载注册表 ═══
$registry = ComponentRegistry::discover();
echo "🧩 G16 verify-components — 注册表: " . count($registry) . " 组件\n";

// ═══ 3. 扫描 x-data 引用 ═══
foreach ($files as $file) {
    $relPath = str_replace(APP_ROOT . '/', '', $file);
    $content = file_get_contents($file);

    // 匹配 x-data="name" 和 x-data="name({...})"
    if (preg_match_all('/x-data\s*=\s*"([a-zA-Z][a-zA-Z0-9]*)/', $content, $matches)) {
        foreach ($matches[1] as $name) {
            $totalRefs++;
            // 跳过 Alpine 内置和简单 JSON 对象
            if (in_array($name, ['dataTable'])) continue; // 内置组件

            if (!ComponentRegistry::verify($name)) {
                $line = substr_count(substr($content, 0, strpos($content, "x-data=\"$name")), "\n") + 1;
                $errors[] = "{$relPath}:{$line} — x-data=\"{$name}\" 组件未在注册表中找到";
            }
        }
    }
}

// ═══ 4. 报告 ═══
echo "模板文件: " . count($files) . " | x-data 引用: {$totalRefs}\n\n";

foreach ($errors as $e) {
    echo "  ❌ {$e}\n";
}

if (count($errors) > 0) {
    echo "\n❌ G16 不通过: " . count($errors) . " 个组件引用未注册\n";
    echo "   运行 php scripts/build-component-registry.php 更新注册表\n";
    exit(1);
}

echo "✅ G16 通过: 所有 x-data 引用均有对应组件\n";
exit(0);
