<?php
/**
 * fix-namespaces.php — 一次性修复所有 modules/ 命名空间
 *
 * 规则: modules/{Module}/{Layer}/{File}.php → namespace Converge\{Module}\{Layer}
 * 例:  modules/Tracking/Infrastructure/Redirector.php → namespace Converge\Tracking\Infrastructure
 *
 * 同时更新所有 use 语句
 *
 * 用法: php scripts/fix-namespaces.php --dry-run    # 预览
 *       php scripts/fix-namespaces.php --write      # 执行
 */
$root = dirname(__DIR__);
$dryRun = !in_array('--write', $argv);

// ═══ Step 1: 扫描 modules/ → 建立 旧namespace → 新namespace 映射 ═══
$nsMap = []; // oldNamespace → newNamespace
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/modules', RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($it as $file) {
    if ($file->getExtension() !== 'php') continue;
    if ($file->getFilename() === 'bootstrap.php') continue;

    $content = file_get_contents($file->getRealPath());
    if (!preg_match('/^namespace\s+([\w\\\\]+)/m', $content, $m)) continue;
    $oldNs = trim($m[1]);

    // 计算模块名 (modules/ 后的第一段)
    $relPath = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));
    if (!preg_match('#^modules/([^/]+)/([^/]+)/#', $relPath, $pm)) continue;
    $module = $pm[1];
    $layer = $pm[2];

    // 新命名空间: Converge\{Module}\{Layer}
    $newNs = "Converge\\{$module}\\{$layer}";

    if ($oldNs !== $newNs) {
        $nsMap[$oldNs] = $newNs;
    }
}

if (empty($nsMap)) {
    echo "✅ All namespaces already correct\n";
    exit(0);
}

echo count($nsMap) . " namespace mismatches found\n";
foreach (array_slice($nsMap, 0, 10) as $old => $new) {
    echo "  {$old}\n    → {$new}\n";
}
if (count($nsMap) > 10) echo "  ... and " . (count($nsMap) - 10) . " more\n";

if ($dryRun) {
    echo "\nDry run. Use --write to apply fixes.\n";
    exit(count($nsMap) > 0 ? 1 : 0);
}

// ═══ Step 2: 修复 modules/ 内的 namespace 声明 ═══
$fixed = 0;
foreach ($it as $file) {
    $content = file_get_contents($file->getRealPath());
    $changed = false;
    foreach ($nsMap as $old => $new) {
        if (str_contains($content, "namespace {$old}")) {
            $content = str_replace("namespace {$old}", "namespace {$new}", $content);
            $changed = true;
        }
    }
    if ($changed) {
        file_put_contents($file->getRealPath(), $content);
        $fixed++;
    }
}
echo "Fixed {$fixed} files in modules/\n";

// ═══ Step 3: 修复所有 use 语句 ═══
$useFixed = 0;
foreach (['public', 'app', 'modules', 'tools', 'bin'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) continue;
    $it2 = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it2 as $file) {
        if ($file->getExtension() !== 'php') continue;
        $content = file_get_contents($file->getRealPath());
        $changed = false;
        foreach ($nsMap as $old => $new) {
            if (str_contains($content, "use {$old}")) {
                $content = str_replace("use {$old}", "use {$new}", $content);
                $content = str_replace("\\{$old}\\", "\\{$new}\\", $content);
                $changed = true;
            }
        }
        if ($changed) {
            file_put_contents($file->getRealPath(), $content);
            $useFixed++;
        }
    }
}
echo "Fixed {$useFixed} files with use statements\n";
echo "\n⚠️  Run: composer dump-autoload\n";
