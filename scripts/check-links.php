#!/usr/bin/env php
<?php
/**
 * check-links.php — 验证 page-registry.json 中所有链接的 PHP 文件存在
 *
 * 用法: php scripts/check-links.php [--json]
 * 退出码: 0=全部通过, 1=有缺失
 */
declare(strict_types=1);

$registryFile = __DIR__ . '/../.claude/reference/page-registry.json';
if (!file_exists($registryFile)) {
    echo "❌ page-registry.json not found\n";
    exit(1);
}

$registry = json_decode(file_get_contents($registryFile), true);
if (!$registry) {
    echo "❌ Invalid JSON in page-registry.json\n";
    exit(1);
}

$errors = [];
$checked = 0;
$root = realpath(__DIR__ . '/..');

// Check menu items
foreach ($registry['menus'] ?? [] as $menuId => $menu) {
    foreach ($menu['items'] ?? [] as $item) {
        $checked++;
        $phpFile = $root . '/' . $item['php'];
        if (!file_exists($phpFile)) {
            $errors[] = "❌ [{$menuId}] {$item['label']}: {$item['url']} → {$item['php']} MISSING";
        }

        // Skip items without template (e.g. logout)
        if (!empty($item['template'])) {
            $tplFile = $root . '/' . $item['template'];
            if (!file_exists($tplFile)) {
                $errors[] = "⚠️  [{$menuId}] {$item['label']}: template {$item['template']} MISSING";
            }
        }
    }
}

// Check standalone pages
foreach ($registry['standalonePages'] ?? [] as $page) {
    $checked++;
    $phpFile = $root . '/' . $page['php'];
    if (!file_exists($phpFile)) {
        $errors[] = "❌ [standalone] {$page['id']}: {$page['url']} → {$page['php']} MISSING";
    }
}

// Report
$json = ($argv[1] ?? '') === '--json';

if (empty($errors)) {
    if ($json) {
        echo json_encode(['ok' => true, 'checked' => $checked], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "✅ All {$checked} links valid — 0 missing files\n";
    }
    exit(0);
}

if ($json) {
    echo json_encode(['ok' => false, 'checked' => $checked, 'errors' => $errors], JSON_PRETTY_PRINT) . "\n";
} else {
    echo implode("\n", $errors) . "\n";
    echo "\n🔴 " . count($errors) . " broken link(s) found. Fix or update page-registry.json.\n";
}
exit(1);
