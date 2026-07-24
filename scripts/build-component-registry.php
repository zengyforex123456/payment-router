<?php
/**
 * build-component-registry.php — TDA Action 层: 组件自动发现 → registry.json
 *
 * 约定: Alpine 组件放 resources/js/components/*.js
 *       文件名即组件注册约定目录
 *
 * 输出: public/build/js/component-registry.json
 *
 * 用法:
 *   php scripts/build-component-registry.php          # 扫描 + 导出
 *   php scripts/build-component-registry.php --verify # 仅验证, 不写入
 */
declare(strict_types=1);

define('APP_ROOT', realpath(__DIR__ . '/..'));

require_once APP_ROOT . '/vendor/autoload.php';

use Converge\UI\Engine\ComponentRegistry;

$verify = in_array('--verify', $argv);

$components = ComponentRegistry::discover();
$outputPath = APP_ROOT . '/public/build/js/component-registry.json';

echo "🧩 ComponentRegistry — 自动发现\n";
echo "搜索目录: resources/js/components/, public/assets/js/components/\n";
echo "发现组件: " . count($components) . "\n";

foreach ($components as $name) {
    echo "  ✅ {$name}\n";
}

if ($verify) {
    echo "\n✅ 验证完成 (--verify: 不写入文件)\n";
    exit(0);
}

ComponentRegistry::export($outputPath);
echo "\n📦 注册表已导出: public/build/js/component-registry.json\n";
echo "   组件数: " . count($components) . "\n";
exit(0);
