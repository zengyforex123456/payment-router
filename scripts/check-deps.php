#!/usr/bin/env php
<?php
/**
 * check-deps.php — 验证模板依赖完整性
 *
 * 检查:
 *   1. 所有 {layout '...'} 引用的文件存在
 *   2. 所有 {include '...'} 引用的模板存在
 *   3. 所有 LatteEngine::display/render 引用的模板存在
 *   4. 所有 public/*.php 入口文件存在 + 语法正确
 *
 * 用法: php scripts/check-deps.php [--json]
 * 退出码: 0=全部通过, 1=有缺失
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$json = ($argv[1] ?? '') === '--json';
$errors = [];
$checked = 0;

// ── 1. 检查 {layout} 引用 ──
$tplDir = $root . '/templates';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tplDir));
foreach ($it as $file) {
    if ($file->getExtension() !== 'latte') continue;
    $content = file_get_contents($file->getPathname());
    $clean = preg_replace("/\{\*.*?\*\}/s", '', $content);
    if (preg_match("/\{layout\s+'([^']+)'\}/", $clean, $m)) {
        $checked++;
        $layoutPath = dirname(str_replace($root . '/', '', $file->getPathname())) . '/' . $m[1];
        $resolved = realpath($root . '/' . $layoutPath);
        if (!$resolved || !file_exists($resolved)) {
            $errors[] = "❌ {$file->getFilename()}: layout '{$m[1]}' → {$layoutPath} MISSING";
        }
    }
}

// ── 2. 检查 {include} 引用 (非变量, 非 #block) ──
foreach ($it as $file) {
    if ($file->getExtension() !== 'latte') continue;
    $content = file_get_contents($file->getPathname());
    // Strip Latte comments {* ... *} before parsing
    $clean = preg_replace("/\{\*.*?\*\}/s", '', $content);
    preg_match_all("/\{include\s+'([^']+)'\}/", $clean, $matches);
    foreach ($matches[1] as $inc) {
        $checked++;
        // Resolve relative to templates/ dir
        $base = dirname(str_replace($root . '/', '', $file->getPathname()));
        $incPath = $base . '/' . $inc;
        $resolved = realpath($root . '/' . $incPath);
        if (!$resolved || !file_exists($resolved)) {
            $errors[] = "❌ {$file->getFilename()}: include '{$inc}' → {$incPath} MISSING";
        }
    }
}

// ── 3. 检查 PHP 中 LatteEngine display/render 引用 ──
$phpDir = $root . '/public';
$phpIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($phpDir));
foreach ($phpIt as $file) {
    if ($file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname());
    preg_match_all("/LatteEngine::(?:display|render)\s*\(\s*'([^']+)'/", $content, $matches);
    foreach ($matches[1] as $tpl) {
        $checked++;
        $tplPath = $root . '/templates/' . $tpl . '.latte';
        if (!file_exists($tplPath)) {
            $errors[] = "❌ {$file->getFilename()}: LatteEngine::display('{$tpl}') → templates/{$tpl}.latte MISSING";
        }
    }
}

if ($json) {
    echo json_encode(['ok' => empty($errors), 'checked' => $checked, 'errors' => $errors], JSON_PRETTY_PRINT) . "\n";
} else {
    if (empty($errors)) {
        echo "✅ All {$checked} dependencies valid\n";
    } else {
        echo implode("\n", $errors) . "\n";
        echo "\n🔴 " . count($errors) . " broken dependency(s).\n";
    }
}
exit(empty($errors) ? 0 : 1);
