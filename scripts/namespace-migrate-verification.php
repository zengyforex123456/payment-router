<?php
/** Namespace migration: Converge\Verification → Converge\Modules\Verification */
declare(strict_types=1);

$root = dirname(__DIR__);
$updated = 0;

// Step 1: modules/Verification/Infrastructure/
$paths = [
    $root . '/modules/Verification/Infrastructure',
    $root . '/modules/Verification/Infrastructure/Probes',
];
foreach ($paths as $dir) {
    if (!is_dir($dir)) continue;
    foreach (glob($dir . '/*.php') as $file) {
        $content = file_get_contents($file);
        $orig = $content;
        $content = str_replace('namespace Converge\\Verification\\Probes;', 'namespace Converge\\Modules\\Verification\\Infrastructure\\Probes;', $content);
        $content = str_replace('namespace Converge\\Verification;', 'namespace Converge\\Modules\\Verification\\Infrastructure;', $content);
        $content = str_replace('use Converge\\Verification\\', 'use Converge\\Modules\\Verification\\Infrastructure\\', $content);
        if ($content !== $orig) {
            file_put_contents($file, $content);
            $updated++;
            echo "  [ns] " . basename($file) . "\n";
        }
    }
}

// Step 2: References across codebase
$dirs = ['app', 'modules', 'public', 'bin', 'tools'];
foreach ($dirs as $dir) {
    $fullDir = $root . '/' . $dir;
    if (!is_dir($fullDir)) continue;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullDir));
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') continue;
        if (str_contains($file->getPathname(), 'vendor')) continue;
        if (str_contains($file->getPathname(), 'modules\\Verification')) continue;
        $content = file_get_contents($file->getPathname());
        $orig = $content;
        $content = str_replace('use Converge\\Verification\\', 'use Converge\\Modules\\Verification\\Infrastructure\\', $content);
        $content = str_replace('\\Converge\\Verification\\', '\\Converge\\Modules\\Verification\\Infrastructure\\', $content);
        if ($content !== $orig) {
            file_put_contents($file->getPathname(), $content);
            $updated++;
            echo "  [ref] " . str_replace($root . '/', '', $file->getPathname()) . "\n";
        }
    }
}
echo "Total: $updated files updated\n";
