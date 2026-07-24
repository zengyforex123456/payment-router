<?php
/** Namespace migration: Converge\Tracking → Converge\Modules\Tracking\Infrastructure */
declare(strict_types=1);

$root = dirname(__DIR__);
$updated = 0;
$files = 0;

// Step 1: Fix namespaces in modules/Tracking/
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/modules/Tracking')
);
foreach ($iter as $file) {
    if ($file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname());
    $orig = $content;

    // namespace Converge\Tracking → namespace Converge\Modules\Tracking\Infrastructure
    $content = preg_replace(
        '/namespace Converge\\\\Tracking(\\\\(\\\\w+))?;/',
        'namespace Converge\\Modules\\Tracking\\Infrastructure$1;',
        $content
    );
    // use Converge\Tracking\ → use Converge\Modules\Tracking\Infrastructure\
    $content = str_replace(
        'use Converge\\Tracking\\',
        'use Converge\\Modules\\Tracking\\Infrastructure\\',
        $content
    );

    if ($content !== $orig) {
        file_put_contents($file->getPathname(), $content);
        $updated++;
        echo "  [ns] " . basename($file->getPathname()) . "\n";
    }
    $files++;
}
echo "Modules/Tracking: $updated namespaces updated ($files files scanned)\n";

// Step 2: Fix references across codebase
$dirs = ['app', 'modules', 'public', 'bin', 'tools'];
foreach ($dirs as $dir) {
    $fullDir = $root . '/' . $dir;
    if (!is_dir($fullDir)) continue;
    $iter2 = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fullDir)
    );
    foreach ($iter2 as $file) {
        if ($file->getExtension() !== 'php') continue;
        if (str_contains($file->getPathname(), 'vendor')) continue;
        if (str_contains($file->getPathname(), 'modules\\Tracking')) continue;

        $content = file_get_contents($file->getPathname());
        $orig = $content;

        $content = str_replace(
            'use Converge\\Tracking\\',
            'use Converge\\Modules\\Tracking\\Infrastructure\\',
            $content
        );
        $content = str_replace(
            '\\Converge\\Tracking\\',
            '\\Converge\\Modules\\Tracking\\Infrastructure\\',
            $content
        );

        if ($content !== $orig) {
            file_put_contents($file->getPathname(), $content);
            $updated++;
            echo "  [ref] " . str_replace($root . '/', '', $file->getPathname()) . "\n";
        }
    }
}
echo "\nTotal: $updated files updated\n";
echo "Now run: php bin/tool sync && composer dump-autoload\n";
