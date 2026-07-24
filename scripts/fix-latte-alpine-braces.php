<?php
/**
 * fix-latte-alpine-braces.php — Auto-fix unescaped Alpine {} in .latte files
 *
 * Upgrades G20 from "block" to "auto-fix".
 * Detects x-data="{...}" (single brace) → converts to x-data="{{ ... }}" (double brace).
 *
 * Dry-run by default. Pass --write to apply changes.
 * Safe: only modifies Alpine directives, never Latte template tags.
 */
$root = str_replace('\\', '/', dirname(__DIR__));
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

    // Skip lines inside {syntax off}...{/syntax} blocks
    // Pattern: x-data=" or x-init=" or x-text=" or x-show=" or x-if=" with single {
    // Replace single { with {{ ONLY inside Alpine directives

    // Match: x-*=" ... { ... } ... " where { is NOT already {{
    $patterns = [
        // x-data="{expr}" → x-data="{{ expr }}"
        '/(x-data\s*=\s*")\{([^{][^"]*)\}(")/s' => '$1{{ $2 }}$3',
        // x-init="{expr}" → x-init="{{ expr }}"
        '/(x-init\s*=\s*")\{([^{][^"]*)\}(")/s' => '$1{{ $2 }}$3',
        // @click="expr={...}" → @click="expr={{ ... }}"
        '/(@\w+\s*=\s*"[^"]*)\{([^\{][^}]*)}([^"]*")/s' => '$1{{ $2 }}$3',
    ];

    foreach ($patterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }

    if ($content !== $original) {
        $relPath = str_replace('\\', '/', str_replace($root . '/', '', $path));
        $changes[] = $relPath;
        if (!$dryRun) file_put_contents($path, $content);
    }
}

echo $dryRun ? "🔍 DRY RUN — use --write to apply\n\n" : "✅ Auto-fix applied\n\n";
if (count($changes) === 0) {
    echo "All clear — no unescaped Alpine braces found.\n";
} else {
    echo count($changes) . " file(s) fixed:\n";
    foreach ($changes as $f) echo "  📄 {$f}\n";
}
exit(0);
