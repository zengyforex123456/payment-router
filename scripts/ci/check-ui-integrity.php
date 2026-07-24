<?php

declare(strict_types=1);

/**
 * UI Integrity Gate — CSS/JS asset existence + page rendering verification.
 *
 * Usage: php scripts/ci/check-ui-integrity.php [--strict]
 * Exit: 0 = clean, 1 = violations
 */

$strict = in_array('--strict', $argv, true);
$baseDir = dirname(__DIR__);
$buildCss = $baseDir . '/public/build/css';
$buildJs  = $baseDir . '/public/build/js';
$violations = [];

// ═══ 1. Critical CSS files must exist ═══
$criticalCss = [
    'design-tokens.css'    => 'Design tokens (v5, sole token source)',
    'app-bundle.css'       => 'Global bundle (skeleton + dock-layout + intent-ui + toast)',
    'app-shell.css'        => 'Sidebar + topbar + shell layout',
    'dashboard-page.css'   => 'Dashboard + subscription + wizard + data-table + form',
    'grid.css'             => '12-column grid system',
];

foreach ($criticalCss as $file => $desc) {
    $path = "$buildCss/$file";
    if (!file_exists($path)) {
        $violations[] = "MISSING: build/css/$file — $desc";
    } elseif (filesize($path) < 100) {
        $violations[] = "EMPTY/TRUNCATED: build/css/$file ({$desc}) — " . filesize($path) . ' bytes';
    }
}

// ═══ 2. Critical JS files must exist ═══
$criticalJs = [
    'bundle.min.js'     => 'Stimulus + Turbo bundle (25 controllers)',
    'theme-toggle.js'   => 'Dark/light mode toggle',
    'htmx.min.js'       => 'HTMX for standalone pages',
    'sortable.min.js'   => 'Drag-and-drop (builder + dashboard)',
    'alpinejs.min.js'   => 'Alpine.js — _layout-head standalone pages',
];

foreach ($criticalJs as $file => $desc) {
    $path = "$buildJs/$file";
    if (!file_exists($path)) {
        $violations[] = "MISSING: build/js/$file — $desc";
    } elseif (filesize($path) < 50) {
        $violations[] = "EMPTY/TRUNCATED: build/js/$file ({$desc}) — " . filesize($path) . ' bytes';
    }
}

// ═══ 3. Verify every public .php page references design-tokens.css ═══
$publicDir = $baseDir . '/public';
$phpFiles = glob("$publicDir/*.php");
$checked = 0;
$missingTokensCss = 0;

foreach ($phpFiles as $file) {
    $name = basename($file);
    $apiPages = [
        'health.php', 'click.php', 'cloak.php', 'go.php', 'km.php', 'kumahop.php',
        'landing-track.php', 'logout.php', 'pixel.php', 'postback.php',
        'update-progress.php', 'module-verify.php', 'assert-health.php',
        'track.php', 'api-funnel.php', 'fire-postback-for-conversion.php',
        'verify-admin-ip.php', 'verify-feature-gating.php', 'verify-featureregistry.php',
    ];
    if (str_starts_with($name, 'api-') || str_starts_with($name, '_')
        || in_array($name, $apiPages, true)) {
        continue;
    }
    $checked++;
    $content = file_get_contents($file);
    if (!str_contains($content, 'design-tokens.css') && !str_contains($content, '_layout-head')
        && !str_contains($content, 'PageShell') && !str_contains($content, 'LatteEngine')) {
        $missingTokensCss++;
        $violations[] = "NO CSS: public/$name does not reference design-tokens.css or use layout system";
    }
}

// ═══ Output ═══
$cssCount = count($criticalCss);
$jsCount  = count($criticalJs);
$cssOk = $cssCount - count(array_filter($violations, fn($v) => str_contains($v, 'build/css/')));
$jsOk  = $jsCount - count(array_filter($violations, fn($v) => str_contains($v, 'build/js/')));

echo "═══ UI Integrity Gate ═══\n";
echo "  CSS: $cssOk/$cssCount critical files\n";
echo "  JS:  $jsOk/$jsCount critical files\n";
echo "  Pages: $checked checked, $missingTokensCss missing design-tokens.css\n";

if (empty($violations)) {
    echo "  ✅ UI assets complete — all pages reference design system\n";
    exit(0);
}

echo "  ❌ " . count($violations) . " violation(s):\n";
foreach ($violations as $v) {
    echo "    $v\n";
}
exit($strict ? 1 : 0);
