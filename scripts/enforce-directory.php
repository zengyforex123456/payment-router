<?php
/**
 * enforce-directory.php — Directory discipline gate
 *
 * Scans staged PHP files and verifies each is in the correct directory.
 * Returns exit code 0 (pass) or 1 (violation).
 *
 * Rules:
 *   class/trait/interface/enum    → app/ or modules/X/
 *   #[Tool] class                  → tools/
 *   Entry points (no class)        → public/ or bin/
 *   Config (define/return array)   → config/
 *
 * Usage:
 *   php scripts/enforce-directory.php           # check staged files
 *   php scripts/enforce-directory.php --all     # check all tracked files
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$mode = in_array('--all', $argv, true) ? 'all' : 'staged';
$violations = 0;
$checked = 0;

// Get file list
if ($mode === 'staged') {
    exec('git diff --cached --name-only --diff-filter=ACM 2>&1', $files, $rc);
    if ($rc !== 0) { echo "Git error\n"; exit(0); } // Not a git repo, skip
} else {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root)
    );
    $files = [];
    foreach ($iter as $f) {
        if ($f->getExtension() !== 'php') continue;
        $path = str_replace('\\', '/', $f->getPathname());
        if (str_contains($path, '/vendor/') || str_contains($path, '/storage/cache/')) continue;
        $files[] = str_replace($root . '/', '', $path);
    }
}

$files = array_filter($files, fn($f) => str_ends_with($f, '.php'));

foreach ($files as $file) {
    $fullPath = "$root/$file";
    if (!file_exists($fullPath)) continue;

    $content = @file_get_contents($fullPath);
    if (!$content || strlen(trim($content)) === 0) continue;

    $checked++;
    $rule = classifyFile($file, $content);

    if ($rule['violation']) {
        echo "  ❌ {$file}\n     {$rule['message']}\n";
        $violations++;
    }
}

if ($violations > 0) {
    echo "\n❌ {$violations} directory violation(s) — BLOCKED\n";
    echo "   Correct locations:\n";
    echo "   - PHP classes  → app/ or modules/{Name}/\n";
    echo "   - #[Tool]      → tools/\n";
    echo "   - Entry points → public/ or bin/\n";
    echo "   - Config       → config/\n";
    exit(1);
}

if ($checked > 0) echo "✅ {$checked} files passed directory check\n";
exit(0);

// ── Classification ────────────────────────────────────

function classifyFile(string $path, string $content): array
{
    $hasClass = (bool) preg_match('/^[ \t]*(class|interface|trait|enum)\s+\w+/m', $content);
    // Only flag #[Tool] if actual attribute on a class (not grep/search string)
    $hasTool  = $hasClass && str_contains($content, '#[Tool');
    $hasEntry = !$hasClass && (str_contains($content, 'header(') || str_contains($content, 'echo '));
    $hasConfig = (bool) preg_match('/^[ \t]*define\(/m', $content);

    // ── Exemptions (check before rules) ──
    if (in_array($path, ['app.php', 'index.php'])) {
        return ['violation' => false, 'message' => 'Root bootstrap (exempt)'];
    }
    if (str_starts_with($path, 'scripts/')) {
        return ['violation' => false, 'message' => 'Script (OK)'];
    }
    if (str_starts_with($path, 'tests/') || str_starts_with($path, 'storage/')) {
        return ['violation' => false, 'message' => 'Tests/Runtime (OK)'];
    }

    // Rule: #[Tool] must be in tools/
    if ($hasTool && !str_starts_with($path, 'tools/')) {
        return ['violation' => true, 'message' => '#[Tool] class must be in tools/'];
    }

    // Rule: PHP class must be in app/ or modules/X/
    if ($hasClass) {
        if (str_starts_with($path, 'app/') || str_starts_with($path, 'modules/') || str_starts_with($path, 'tools/') || str_starts_with($path, 'kernel/')) {
            return ['violation' => false, 'message' => 'OK'];
        }
        return ['violation' => true, 'message' => 'PHP class must be in kernel/ app/ modules/ or tools/'];
    }

    // Rule: Entry points (header/echo, no class) must be in public/ or bin/
    if ($hasEntry && !str_starts_with($path, 'public/') && !str_starts_with($path, 'bin/')) {
        return ['violation' => true, 'message' => 'Entry point must be in public/ or bin/'];
    }

    // Rule: Config definitions must be in config/
    if ($hasConfig && !str_starts_with($path, 'config/')) {
        return ['violation' => true, 'message' => 'Config file must be in config/'];
    }

    return ['violation' => false, 'message' => 'Pass'];
}
