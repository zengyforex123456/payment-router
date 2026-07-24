<?php
/**
 * G19: No bare HTML pages — all pages must use LatteEngine::display()
 *
 * Detects public/*.php files that output bare HTML (bypassing _layout.latte sidebar).
 * Pattern: <!DOCTYPE html> or <html> directly in public/*.php
 *
 * Exemptions: index.php (SPA), login-v2.php (standalone auth), api-*.php, health check
 */

$root = str_replace('\\', '/', dirname(__DIR__));
$json = in_array('--json', $argv);
$staged = in_array('--staged', $argv);

$files = [];
if ($staged) {
    exec('git diff --cached --name-only --diff-filter=ACM 2>&1', $stagedFiles);
    $files = array_filter($stagedFiles, fn($f) => str_ends_with($f, '.php') && str_starts_with($f, 'public/'));
} else {
    foreach (glob("{$root}/public/*.php") as $f) {
        $files[] = str_replace($root . '/', '', str_replace('\\', '/', $f));
    }
}

// Exemptions: standalone pages that intentionally don't use _layout
$exempt = ['index.php', 'login-v2.php', 'login.php', 'logout.php', 'health.php',
    'api-intent.php', 'api-requirements.php', 'api-track.php', 'fire-postback-for-conversion.php',
    'click-lookup.php', 'km.php', '_layout-head.php',
    // Auth pages (standalone, like login)
    '2fa-setup.php', '2fa-verify.php', 'register-v2.php', 'register.php',
    // Design/dev tools (standalone apps)
    'builder.php',  'design-health-dashboard.php', 'design-token-lab.php',
    'showroom.php', 'styleguide.php', 'tokens.php', 'ui-check.php',
    // Demo pages
    'demo-campaign-stats.php', 'demo-dashboard.php', 'demo-flow-builder.php',
    'demo-index.php', 'demo-reports.php',
    // Ops tools
    'staging-verify.php', 'view-cron-log.php',
];

$violations = [];
foreach ($files as $file) {
    $base = basename($file);
    if (in_array($base, $exempt)) continue;
    if (str_starts_with($base, 'api-')) continue;

    $content = @file_get_contents("{$root}/{$file}");
    if (!$content) continue;

    // Check for bare HTML output (not via LatteEngine)
    if (preg_match('/<\!DOCTYPE\s+html/i', $content)
        && !preg_match('/LatteEngine::display/', $content)) {
        $violations[] = $file;
    }
}

if ($json) {
    echo json_encode([
        'gate' => 'G19_bare_html',
        'violations' => count($violations),
        'files' => $violations,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(count($violations) > 0 ? 1 : 0);
}

if (count($violations) === 0) {
    echo "✅ G19 Bare HTML: 0 standalone pages (all use LatteEngine + _layout)\n";
    exit(0);
}

echo "🚫 G19 Bare HTML: " . count($violations) . " page(s) bypass _layout.latte\n\n";
foreach ($violations as $f) {
    echo "  📄 {$f}\n";
}
echo "\n  💡 Fix: Replace <!DOCTYPE html> with LatteEngine::display('pages/xxx', [...])\n";
echo "     Template must use {layout '../_layout.latte'} for Activity Bar sidebar.\n";

exit(1);
