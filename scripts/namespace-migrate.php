<?php
/** Generic namespace migrator for app/ → modules/ merges */
declare(strict_types=1);

if ($argc < 3) {
    die("Usage: php namespace-migrate.php <ModuleName> [--dry-run]\n");
}

$module = $argv[1];
$dryRun = in_array('--dry-run', $argv, true);
$root = dirname(__DIR__);
$updated = 0;

$oldNs = "Converge\\{$module}";
$newNs = "Converge\\Modules\\{$module}\\Infrastructure";

echo ($dryRun ? "[DRY-RUN] " : "") . "Migrating: {$oldNs} → {$newNs}\n";

// Scan moved files in modules/X/
$targetDir = "{$root}/modules/{$module}/Infrastructure";
if (is_dir($targetDir)) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($targetDir)
    );
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') continue;
        $content = file_get_contents($file->getPathname());
        $orig = $content;

        // Fix namespace declaration
        $content = preg_replace(
            '/namespace ' . preg_quote($oldNs, '/') . '(\\\\(\w+))?;/',
            $newNs . '$1;',
            $content
        );
        // Fix use/type references to old namespace
        $content = str_replace($oldNs . '\\', $newNs . '\\', $content);

        if ($content !== $orig) {
            if (!$dryRun) file_put_contents($file->getPathname(), $content);
            $updated++;
            echo "  [ns] " . str_replace($root . '/', '', $file->getPathname()) . "\n";
        }
    }
}

// Fix references across codebase
$dirs = ['app', 'modules', 'public', 'bin', 'tools'];
foreach ($dirs as $dir) {
    $fullDir = $root . '/' . $dir;
    if (!is_dir($fullDir)) continue;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fullDir)
    );
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') continue;
        if (str_contains($file->getPathname(), 'vendor')) continue;
        if (str_contains($file->getPathname(), "modules\\{$module}")) continue;

        $content = file_get_contents($file->getPathname());
        $orig = $content;
        $content = str_replace($oldNs . '\\', $newNs . '\\', $content);

        if ($content !== $orig) {
            if (!$dryRun) file_put_contents($file->getPathname(), $content);
            $updated++;
            echo "  [ref] " . str_replace($root . '/', '', $file->getPathname()) . "\n";
        }
    }
}

echo ($dryRun ? "[DRY-RUN] Would update " : "") . "Total: {$updated} files\n";
if (!$dryRun) {
    echo "\nNext: rm -rf app/{$module} && composer dump-autoload\n";
}
