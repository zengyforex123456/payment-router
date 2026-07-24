<?php
/**
 * G18: CSS var() dark fallback gate
 *
 * Detects hardcoded dark colors used as fallback values in var() calls.
 * Pattern: var(--xxx, #darkValue) or var(--xxx, rgba(dark))
 *
 * Dark fallbacks activate when CSS variable resolution fails (missing var,
 * load order issue, namespace mismatch), silently switching the page to
 * dark theme colors. This makes text invisible in light mode.
 *
 * Usage:
 *   php scripts/check-css-fallbacks.php            All files
 *   php scripts/check-css-fallbacks.php --staged   Staged only
 *   php scripts/check-css-fallbacks.php --json     JSON output (CI)
 */

$root = str_replace('\\', '/', dirname(__DIR__));
$json = in_array('--json', $argv);
$staged = in_array('--staged', $argv);

// ── Collect files to scan ──
$files = [];

if ($staged) {
    exec('git diff --cached --name-only --diff-filter=ACM 2>&1', $stagedFiles);
    $files = array_filter($stagedFiles, fn($f) => preg_match('/\.(latte|php|css)$/', $f));
} else {
    // Scan all template, PHP (public), and CSS files (exclude build output + node_modules)
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        $rel = str_replace('\\', '/', str_replace($root . '/', '', $path));
        if (str_starts_with($rel, 'public/build/')) continue;
        if (str_starts_with($rel, 'node_modules/')) continue;
        if (str_starts_with($rel, 'storage/')) continue;
        if (preg_match('/\.(latte|css)$/', $rel)
            || (preg_match('/\.php$/', $rel) && str_starts_with($rel, 'public/'))
            || (preg_match('/\.php$/', $rel) && str_starts_with($rel, 'templates/'))
        ) {
            $files[] = $rel;
        }
    }
}

// ── Patterns that match dark fallback values ──
// Dark hex: #0xxxxx, #1xxxxx, #2xxxxx (dark navy/charcoal range)
// Dark rgba: rgba(0, ..., rgba(1, ..., rgba(2, ..., rgba(0...
// Near-white text on light backgrounds: #fxxxxx, #exxxxx (these are dark-theme text colors)
$darkPatterns = [
    // Dark backgrounds as fallback
    'var\(--[^,]+,\s*(#[0-2][0-9a-fA-F]{5})\b' => 'dark background hex fallback in var()',
    'var\(--[^,]+,\s*rgba\(\s*(0|[1-2][0-9])\s*,' => 'dark rgba background fallback in var()',
    // Light text as fallback (near-white on light bg = invisible)
    'var\(--[^,]+,\s*(#[ef][0-9a-fA-F]{5})\b' => 'near-white text fallback in var() (invisible in light theme)',
    // Hardcoded dark body bg in PHP echo statements
    'background\s*:\s*(#[0-2][0-9a-fA-F]{5})\b' => 'hardcoded dark background',
    'body\s*\{\s*background\s*:\s*(#[0-2][0-9a-fA-F]{5})\b' => 'hardcoded dark body background',
];

$violations = [];

foreach ($files as $file) {
    $fullPath = "{$root}/{$file}";
    if (!file_exists($fullPath)) continue;

    $content = @file_get_contents($fullPath);
    if ($content === false) continue;

    $lines = explode("\n", $content);

    foreach ($lines as $i => $line) {
        $lineNum = $i + 1;

        // Skip comment lines and noescape markers
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*')) continue;
        if (str_contains($line, '/* token */')) continue;

        // Check dark background / text fallbacks in var()
        if (preg_match('/var\(--[^,]+,\s*(#[0-2][0-9a-fA-F]{5})\b/', $line, $m)) {
            $violations[] = [
                'file' => $file,
                'line' => $lineNum,
                'value' => $m[1],
                'reason' => 'dark hex fallback in var() — activates in light theme if variable is undefined',
                'snippet' => trim($line),
            ];
        }
        if (preg_match('/var\(--[^,]+,\s*(#[ef][0-9a-fA-F]{5})\b/', $line, $m)) {
            $violations[] = [
                'file' => $file,
                'line' => $lineNum,
                'value' => $m[1],
                'reason' => 'near-white text fallback in var() — invisible in light theme',
                'snippet' => trim($line),
            ];
        }
        if (preg_match('/var\(--[^,]+,\s*rgba\(\s*(0|[1-2][0-9])\s*,/', $line, $m)) {
            $violations[] = [
                'file' => $file,
                'line' => $lineNum,
                'value' => 'rgba(dark)',
                'reason' => 'dark rgba fallback in var() — activates in light theme if variable is undefined',
                'snippet' => trim($line),
            ];
        }
    }
}

// ── Exemptions ──
// Sidebar variables are intentionally always dark
// builder.php and demo-*.php are standalone dark-themed pages
$exemptPatterns = [
    '--sidebar-bg', '--sidebar-text', '--sidebar-muted', '--sidebar-hover',
    '--color-sidebar', '--color-sidebar-hover', '--color-sidebar-muted',
    'builder.php', 'demo-', 'ui-check.php', 'design-token-lab.php',
];

$violations = array_filter($violations, function ($v) use ($exemptPatterns) {
    foreach ($exemptPatterns as $pat) {
        if (str_contains($v['file'], $pat)) return false;
        if (str_contains($v['snippet'], $pat)) return false;
    }
    return true;
});

$violations = array_values($violations);

// ── Output ──
if ($json) {
    echo json_encode([
        'gate' => 'G18_css_fallbacks',
        'violations' => count($violations),
        'details' => $violations,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(count($violations) > 0 ? 1 : 0);
}

if (count($violations) === 0) {
    echo "✅ G18 CSS fallbacks: 0 dark fallback values\n";
    exit(0);
}

echo "🚫 G18 CSS fallbacks: " . count($violations) . " dark fallback value(s) found\n\n";
foreach ($violations as $v) {
    echo "  📄 {$v['file']}:{$v['line']} — {$v['value']}\n";
    echo "     → {$v['reason']}\n";
    echo "     → Fix: remove the fallback value: var(--xxx) instead of var(--xxx, #darkValue)\n";
    echo "     → Or: use var(--xxx, var(--light-token))\n\n";
}
echo "  💡 These fallback values are unnecessary — design-tokens.css defines all variables\n";
echo "     in both :root (light) and html.dark (dark). Fallbacks only cause silent dark-mode\n";
echo "     leakage into light theme when a variable is misspelled or undefined.\n";

exit(1);
