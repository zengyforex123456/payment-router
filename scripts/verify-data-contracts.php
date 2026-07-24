<?php
/**
 * verify-data-contracts.php — TDA 可验证门禁 G17: PageContract 数据契约验证
 *
 * 扫描 public/*.php 中的 LatteEngine::display() 调用,
 * 提取页面名和传入参数, 与 contracts/pages/{name}.php 交叉验证.
 *
 * 用法:
 *   php scripts/verify-data-contracts.php              # 全量检查
 *   php scripts/verify-data-contracts.php --staged     # 仅 staged 文件
 *
 * 退出码: 0 = 全部一致, 1 = 有契约违规
 */
declare(strict_types=1);

define('APP_ROOT', realpath(__DIR__ . '/..'));

$stagedOnly = in_array('--staged', $argv);
$errors     = [];
$warnings   = [];
$totalCalls = 0;

// ═══ 1. 收集 PHP 控制器文件 ═══
$files = [];
if ($stagedOnly) {
    exec('git diff --cached --name-only --diff-filter=ACM 2>&1', $staged);
    foreach ($staged as $f) {
        if (str_ends_with($f, '.php') && str_starts_with($f, 'public/')) {
            $files[] = APP_ROOT . '/' . $f;
        }
    }
} else {
    $files = glob(APP_ROOT . '/public/*.php');
}

if (empty($files)) {
    echo "📋 G17 verify-data-contracts: 无文件需检查\n";
    exit(0);
}

echo "📋 G17 verify-data-contracts — " . count($files) . " 控制器\n";

// ═══ 2. 加载所有 PageContract ═══
$contractDir = APP_ROOT . '/contracts/pages';
$contracts = [];
if (is_dir($contractDir)) {
    foreach (glob($contractDir . '/*.php') as $cf) {
        $pageName = basename($cf, '.php');
        $contracts[$pageName] = require $cf;
    }
}

// ═══ 3. 扫描 LatteEngine::display() 调用 ═══
foreach ($files as $file) {
    $relPath = str_replace(APP_ROOT . '/', '', $file);
    $content = file_get_contents($file);

    // 匹配 LatteEngine::display('pageName', ...) 或 LatteEngine::display("pageName", ...)
    if (preg_match_all("/LatteEngine::display\s*\(\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
        foreach ($matches[1] as $i => $pageRef) {
            $totalCalls++;
            // 提取纯页面名 (去掉 pages/ 前缀)
            $pageName = str_replace('pages/', '', $pageRef);

            // 检查是否有对应契约
            if (!isset($contracts[$pageName])) {
                $line = substr_count(substr($content, 0, strpos($content, "LatteEngine::display")), "\n") + 1;
                $warnings[] = "{$relPath}:{$line} — 页面 '{$pageName}' 缺少 PageContract (contracts/pages/{$pageName}.php)";
                continue;
            }

            // 检查传入的必需字段
            $contract = $contracts[$pageName];
            $requiredFields = array_filter($contract, fn($v, $k) => !str_ends_with($k, '?'), ARRAY_FILTER_USE_BOTH);
            $requiredNames = array_keys($requiredFields);

            foreach ($requiredNames as $field) {
                // 简单检查: 参数中是否包含该字段名
                if (!preg_match("/['\"]" . preg_quote($field, '/') . "['\"]\s*=>/", $content)) {
                    $line = substr_count(substr($content, 0, strpos($content, "LatteEngine::display")), "\n") + 1;
                    $errors[] = "{$relPath}:{$line} — '{$pageName}' 缺少必需字段 '{$field}' (PageContract 要求)";
                }
            }
        }
    }
}

// ═══ 4. 报告 ═══
echo "LatteEngine::display() 调用: {$totalCalls}\n";
echo "PageContract 文件: " . count($contracts) . "\n\n";

foreach ($errors as $e) {
    echo "  ❌ {$e}\n";
}
foreach ($warnings as $w) {
    echo "  ⚠️  {$w}\n";
}

$total = count($errors) + count($warnings);
echo "\n──────────────────────────────────────\n";
echo "❌ " . count($errors) . " 错误 | ⚠️  " . count($warnings) . " 警告\n";

if (count($errors) > 0) {
    echo "❌ G17 不通过 — 请补全 PageContract 必需字段\n";
    exit(1);
}

echo "✅ G17 通过\n";
exit(0);
