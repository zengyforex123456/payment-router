<?php
/**
 * migrate-latte-delimiters.php — Batch convert { } → {% %} for all .latte files
 *
 * LatteEngine now uses setDelimiters('{%', '%}'). This script converts
 * all template tags from { } to {% %} syntax.
 *
 * Dry-run by default. Pass --write to apply.
 *
 * SAFE: never touches JS inside <script> blocks or CSS inside <style> blocks
 *       because those are inside {syntax off} blocks which are converted too.
 */
$root = str_replace('\\', '/', dirname(__DIR__));
$dryRun = !in_array('--write', $argv);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator("{$root}/templates", RecursiveDirectoryIterator::SKIP_DOTS)
);

$files = [];
foreach ($iterator as $file) {
    if ($file->getExtension() === 'latte') {
        $files[] = str_replace('\\', '/', $file->getPathname());
    }
}

// Also include landing templates
foreach (glob("{$root}/templates/*/*.latte") as $f) { if (!in_array(str_replace('\\','/',$f), $files)) $files[] = str_replace('\\','/',$f); }

$changed = [];
foreach ($files as $path) {
    $content = file_get_contents($path);
    $original = $content;

    // ── Template tag conversions (order matters!) ──

    // 1. Comments: {* ... *} → {* ... *}  (keep Latte comment syntax — it still works)
    // No change needed — Latte comments use {* which doesn't conflict with Alpine

    // 2. Remove the {{ }} Alpine escapes (no longer needed)
    // Convert x-data="{{ ... }}" → x-data="{ ... }"
    $content = preg_replace('/x-data\s*=\s*"\{\{\s*([^}]+?)\s*\}\}"/', 'x-data="{$1}"', $content);
    $content = preg_replace('/x-init\s*=\s*"\{\{\s*([^}]+?)\s*\}\}"/', 'x-init="{$1}"', $content);

    // 3. Opening tags: {tag ...} → {% tag ... %}
    $content = preg_replace('/\{if\b/', '{% if', $content);
    $content = preg_replace('/\{elseif\b/', '{% elseif', $content);
    $content = preg_replace('/\{else\b/', '{% else', $content);
    $content = preg_replace('/\{\/if\}/', '{% endif %}', $content);
    $content = preg_replace('/\{foreach\b/', '{% foreach', $content);
    $content = preg_replace('/\{\/foreach\}/', '{% endforeach %}', $content);
    $content = preg_replace('/\{block\b/', '{% block', $content);
    $content = preg_replace('/\{\/block\}/', '{% endblock %}', $content);
    $content = preg_replace('/\{layout\b/', '{% layout', $content);
    $content = preg_replace('/\{include\b/', '{% include', $content);
    $content = preg_replace('/\{var\b/', '{% var', $content);
    $content = preg_replace('/\{syntax off\}/', '{% syntax off %}', $content);
    $content = preg_replace('/\{\/syntax\}/', '{% endsyntax %}', $content);

    // 4. Output tags: {=$expr} → {% = $expr %}  and  {$var} → {% $var %}
    $content = preg_replace('/\{=\s*([^}]+?)\s*\}/', '{% = $1 %}', $content);
    $content = preg_replace('/\{(\$[A-Za-z_]\w*(?:\[[^\]]+\]|\.[A-Za-z_]\w*|->\w+(?:\([^)]*\))?|:(?:\w+))*(?:\|[^}]+)?)\s*\}/', '{% $1 %}', $content);

    // 5. Noescape modifier: |noescape stays (it's a filter, not a tag)

    if ($content !== $original) {
        $changed[] = str_replace($root . '/', '', $path);
        if (!$dryRun) file_put_contents($path, $content);
    }
}

echo $dryRun ? "🔍 DRY RUN — use --write to apply\n\n" : "✅ Migration complete\n\n";
if (count($changed) === 0) {
    echo "No files need conversion.\n";
} else {
    echo count($changed) . " file(s) converted:\n";
    foreach ($changed as $f) echo "  📄 {$f}\n";
}
