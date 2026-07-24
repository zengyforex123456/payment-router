<?php
/**
 * fix-latte-delimiters.php — Batch convert {% %} → { } in all .latte files
 *
 * The previous migration to {% %} was never rolled back. Latte 3.1.4 only
 * supports { } delimiters. This script restores the correct syntax.
 *
 * Usage: php scripts/fix-latte-delimiters.php [--dry]
 */
declare(strict_types=1);

$dry = in_array('--dry', $argv ?? []);
$baseDir = __DIR__ . '/../templates';
$count = 0;
$fixed = 0;

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if ($file->getExtension() !== 'latte') continue;
    $path = $file->getPathname();
    $original = file_get_contents($path);
    if ($original === false) continue;
    $count++;

    $content = $original;

    // Step 1: Handle {syntax off} ... {/syntax} blocks first (don't touch content inside)
    // Step 2: Convert block-level tags
    $content = preg_replace('/\{%\s*syntax off\s*%\}/', '{syntax off}', $content);
    $content = preg_replace('/\{%\s*endsyntax\s*%\}/', '{/syntax}', $content);

    // Block tags with closing pair
    $blockTags = [
        'layout', 'block', 'foreach', 'if', 'elseif', 'else', 'var',
        'include', 'php', 'define', 'content', 'capture',
    ];
    // Opening: {% tag ... %} → {tag ...}
    foreach ($blockTags as $tag) {
        $content = preg_replace('/\{%\s*' . $tag . '(\s|%})/', '{' . $tag . '$1', $content);
    }
    // Closing: {% /tag %} → {/tag}
    $closeTags = ['layout', 'block', 'foreach', 'if', 'php', 'define', 'capture', 'syntax', 'content'];
    foreach ($closeTags as $tag) {
        $content = preg_replace('/\{%\s*\/' . $tag . '\s*%\}/', '{/' . $tag . '}', $content);
    }
    // Alternate closing forms
    $content = preg_replace('/\{%\s*endforeach\s*%\}/', '{/foreach}', $content);
    $content = preg_replace('/\{%\s*endblock\s*%\}/', '{/block}', $content);
    $content = preg_replace('/\{%\s*endif\s*%\}/', '{/if}', $content);
    $content = preg_replace('/\{%\s*endphp\s*%\}/', '{/php}', $content);

    // Output tags: {% $var|filter %} → {$var|filter}
    $content = preg_replace('/\{%\s*\$(\w+(?:[-\>\[\]\"\'\w\s\(\)]*)?)((?:\|\w+(?:\s*:\s*[^%}]+)?)*)\s*%\}/', '{\$$1$2}', $content);

    // Output with =: {% =expr|noescape %} → {=expr|noescape}
    $content = preg_replace('/\{%\s*=\s*([^%}]+?)\s*%\}/', '{=$1}', $content);

    // Simple variable output: {% $var %} → {$var}
    $content = preg_replace('/\{%\s*\$([a-zA-Z_]\w*(?:\[[^\]]+\])*(?:->\w+(?:\([^)]*\))?)*)\s*%\}/', '{\$$1}', $content);

    // Remaining {% ... %} that weren't matched — try generic conversion
    // Be conservative: only convert simple cases
    $content = preg_replace('/\{%\s*(\w+)\s*%\}/', '{$1}', $content);

    // Clean up double {$ from previous conversions
    $content = str_replace('{${', '{$', $content);

    if ($content !== $original) {
        $fixed++;
        $relPath = str_replace($baseDir . '/', '', $path);
        if ($dry) {
            echo "  [DRY] Would fix: {$relPath}\n";
        } else {
            file_put_contents($path, $content);
            echo "  ✅ {$relPath}\n";
        }
    }
}

echo "\n{$fixed}/{$count} files " . ($dry ? "would be" : "") . " fixed\n";
if ($dry) echo "Run without --dry to apply changes.\n";
