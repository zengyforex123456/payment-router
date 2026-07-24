<?php
/**
 * G20: Latte-Alpine brace conflict detection
 *
 * Alpine.js uses { x: 1 } blocks. Latte uses {expr} for template tags.
 * When Alpine appears in a Latte attribute, { ... } MUST use {{ and }}
 * (double braces) to tell Latte "output literally".
 *
 * Detects: unescaped Alpine x-data="\{...\}" etc in .latte files.
 *
 * Usage:
 *   php scripts/check-latte-alpine-braces.php            Check all files
 *   php scripts/check-latte-alpine-braces.php --staged   Staged only
 */

$root = str_replace('\\', '/', dirname(__DIR__));
$json = in_array('--json', $argv);
$staged = in_array('--staged', $argv);

$files = [];
if ($staged) {
    exec('git diff --cached --name-only --diff-filter=ACM 2>&1', $stagedFiles);
    $files = array_filter($stagedFiles, fn($f) => str_ends_with($f, '.latte') && !str_starts_with($f, 'node_modules/'));
} else {
    foreach (glob("{$root}/templates/**/*.latte") as $f) {
        $files[] = str_replace($root . '/', '', str_replace('\\', '/', $f));
    }
}

$violations = [];
foreach ($files as $file) {
    $content = @file_get_contents("{$root}/{$file}");
    if (!$content) continue;
    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        $lineNum = $i + 1;
        // Skip {syntax off} blocks — those are intentionally raw
        // Match: x-data="{...}" or x-init="{...}" or x-text="...{...}"
        // where the { is NOT doubled ({{ means escaped)
        // Only flag Alpine object literals: {word: value} (NOT Latte {$var} or JS {expr})
        // Dangerous: x-data="{ open: false }" — Latte tries to parse {open:...} as tag
        // Safe: x-data="{$alpineData}" — Latte outputs variable, no conflict
        // Safe: x-text="fn({$var})" — Latte handles {$var} correctly
        if (preg_match('/x-(?:data|init)\s*=\s*"\s*\{(?!\{\s*\$)[a-zA-Z_]\w*\s*:/', $line)
            && !str_contains($line, 'syntax off')) {
            $violations[] = ['file' => $file, 'line' => $lineNum, 'snippet' => trim($line)];
        }
    }
}

if ($json) {
    echo json_encode(['gate' => 'G20_alpine_braces', 'violations' => count($violations), 'details' => $violations], JSON_PRETTY_PRINT) . "\n";
    exit(count($violations) > 0 ? 1 : 0);
}

if (count($violations) === 0) {
    echo "✅ G20 Alpine braces: 0 conflicts (all Alpine {} properly escaped)\n";
    exit(0);
}

echo "🚫 G20 Alpine braces: " . count($violations) . " unescaped Alpine {} in Latte templates\n\n";
echo "   These will cause 'Unexpected tag' errors in Latte parser.\n";
echo "   Fix: use {{ }} (double braces) in x-data=\"...\" attributes.\n\n";
echo "   Example: x-data=\"{{ open: false }}\" (NOT x-data=\"{open: false}\")\n\n";
foreach ($violations as $v) {
    echo "  📄 {$v['file']}:{$v['line']} — {$v['snippet']}\n";
}
exit(1);
