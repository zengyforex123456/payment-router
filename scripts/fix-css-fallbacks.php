<?php
/**
 * fix-css-fallbacks.php — Batch strip dark fallback values from var() calls in .latte templates
 *
 * var(--bg-card, #111720) → var(--bg-card)
 * var(--text-primary, #f0f4fc) → var(--text-primary)
 *
 * Dry-run by default. Pass --write to actually modify files.
 */

$root = dirname(__DIR__);
$dryRun = !in_array('--write', $argv);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator("{$root}/templates", RecursiveDirectoryIterator::SKIP_DOTS)
);

$changes = [];
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'latte') continue;

    $path = $file->getPathname();
    $content = file_get_contents($path);
    $original = $content;

    // Strip dark hex fallbacks: var(--xxx, #000000) → var(--xxx)
    // But KEEP var() calls that have no fallback: var(--xxx)
    // Also KEEP fallbacks that use another var(): var(--xxx, var(--yyy))
    $content = preg_replace(
        '/var\((--[^,]+),\s*#[0-9a-fA-F]{3,8}\s*\)/',
        'var($1)',
        $content
    );

    // Strip rgba fallbacks: var(--xxx, rgba(...)) → var(--xxx)
    $content = preg_replace(
        '/var\((--[^,]+),\s*rgba\([^)]+\)\s*\)/',
        'var($1)',
        $content
    );

    if ($content !== $original) {
        $relPath = str_replace('\\', '/', str_replace($root . '/', '', $path));
        $changes[] = $relPath;

        if (!$dryRun) {
            file_put_contents($path, $content);
        }
    }
}

echo $dryRun ? "🔍 DRY RUN — use --write to apply changes\n\n" : "✅ Changes written\n\n";

if (count($changes) === 0) {
    echo "No changes needed.\n";
} else {
    echo count($changes) . " file(s) would be changed:\n";
    foreach ($changes as $f) {
        echo "  📄 {$f}\n";
    }
}
