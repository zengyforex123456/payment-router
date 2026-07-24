#!/usr/bin/env php
<?php
/**
 * enforce-design-tokens.php — 设计令牌强制门禁
 *
 * 检测 6 类违规:
 *   1. Tailwind 任意值 (p-[13px], w-[333px])
 *   2. 硬编码 hex 颜色 (非 var(--xxx))
 *   3. 硬编码 px 间距 (padding/margin/gap)
 *   4. 硬编码 font-size px
 *   5. 硬编码 border-radius px
 *   6. !important 覆写令牌
 *
 * 用法:
 *   php scripts/enforce-design-tokens.php             全量扫描
 *   php scripts/enforce-design-tokens.php --staged    仅 staged 新增违规
 *   php scripts/enforce-design-tokens.php --json      JSON 输出
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$JSON = in_array('--json', $argv);
$STAGED = in_array('--staged', $argv);

$violations = [];
$scanned = 0;

// ═══ Collect files ═══
$files = [];
if ($STAGED) {
    $output = [];
    exec('git diff --cached --name-only 2>&1', $output);
    foreach ($output as $f) {
        $f = trim($f);
        if ($f && preg_match('/\.(php|latte|css|html)$/', $f) && file_exists("$ROOT/$f")) {
            $files[] = "$ROOT/$f";
        }
    }
} else {
    $files = array_merge(
        glob($ROOT . '/templates/**/*.latte') ?: [],
        glob($ROOT . '/public/*.php') ?: [],
        glob($ROOT . '/public/assets/css/*.css') ?: [],
        glob($ROOT . '/modules/**/*.php') ?: [],
    );
}

if (empty($files)) {
    echo $JSON ? '{"status":"ok","message":"no files to scan"}' : "  ✅ No scannable files\n";
    exit(0);
}

// ═══ Scan ═══
$counts = ['arbitrary' => 0, 'hex' => 0, 'px' => 0, 'fontSize' => 0, 'radius' => 0, 'important' => 0];

foreach ($files as $file) {
    $scanned++;
    $lines = file($file) ?: [];
    $rel = str_replace($ROOT . '/', '', $file);

    // tokens.css IS the token definition — exempt from self-check
    if (str_contains($rel, 'tokens.css')) continue;

    foreach ($lines as $lineno => $line) {
        $n = $lineno + 1;

        // Skip comments and CSS variable definitions
        if (str_contains($line, 'var(--')) continue;
        if (preg_match('/^\s*\/\/|^\s*\*|^\s*\/\*|^\s*#/', $line)) continue;

        // 1. Tailwind arbitrary values: p-[13px], w-[333px], text-[#abc]
        if (preg_match('/[a-z]+-\[(\d+px|#[0-9a-fA-F]{3,6})\]/', $line, $m)) {
            $violations[] = ['file' => $rel, 'line' => $n, 'type' => 'arbitrary',
                'msg' => "Tailwind arbitrary value: {$m[0]} → use token class",
                'suggestion' => 'p-4, text-accent, etc.'];
            $counts['arbitrary']++;
        }

        // 2. Hardcoded hex (exclude data: URIs and URLs)
        if (preg_match('/(?<!url\()(#[0-9a-fA-F]{6})\b|(?<!url\()(#[0-9a-fA-F]{3})\b/', $line, $m)) {
            $violations[] = ['file' => $rel, 'line' => $n, 'type' => 'hex',
                'msg' => "Hardcoded hex: {$m[0]} → use var(--color-*) or var(--content-*)",
                'suggestion' => 'var(--content-primary)'];
            $counts['hex']++;
        }

        // 3. Hardcoded px spacing
        if (preg_match('/(padding|margin|gap)\s*:\s*\d+px/i', $line, $m)) {
            $violations[] = ['file' => $rel, 'line' => $n, 'type' => 'px',
                'msg' => "Hardcoded px: {$m[0]} → use var(--space-*)",
                'suggestion' => 'padding: var(--space-4)'];
            $counts['px']++;
        }

        // 4. Hardcoded font-size px
        if (preg_match('/font-size\s*:\s*\d+px/i', $line, $m)) {
            $violations[] = ['file' => $rel, 'line' => $n, 'type' => 'fontSize',
                'msg' => "Hardcoded font-size px → use var(--text-*)",
                'suggestion' => 'font-size: var(--text-base)'];
            $counts['fontSize']++;
        }

        // 5. Hardcoded border-radius px
        if (preg_match('/border-radius\s*:\s*\d+px/i', $line, $m)) {
            $violations[] = ['file' => $rel, 'line' => $n, 'type' => 'radius',
                'msg' => "Hardcoded border-radius px → use var(--radius-*)",
                'suggestion' => 'border-radius: var(--radius-md)'];
            $counts['radius']++;
        }

        // 6. !important
        if (preg_match('/!important/', $line)) {
            $violations[] = ['file' => $rel, 'line' => $n, 'type' => 'important',
                'msg' => '!important overwrites token cascade — restructure CSS priority'];
            $counts['important']++;
        }
    }
}

// ═══ Output ═══
$total = array_sum($counts);

if ($JSON) {
    echo json_encode([
        'status' => $total > 0 ? 'violations' : 'clean',
        'scanned' => $scanned,
        'violations' => $total,
        'by_type' => $counts,
        'details' => array_slice($violations, 0, 50), // cap at 50
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($total > 0 ? 1 : 0);
}

echo "═══ 设计令牌门禁 — $scanned files ═══\n";

if ($total === 0) {
    echo "  ✅ All files use token-based design\n";
    exit(0);
}

foreach ($counts as $type => $c) {
    if ($c > 0) echo "  {$type}: {$c}\n";
}

echo "\n  Top violations:\n";
$shown = 0;
foreach (array_slice($violations, 0, 15) as $v) {
    echo "  {$v['file']}:{$v['line']} — {$v['msg']}\n";
    echo "    → {$v['suggestion']}\n";
    $shown++;
}
if (count($violations) > 15) echo "  ... and " . (count($violations) - 15) . " more\n";

echo "\n  ❌ {$total} token violations — BLOCKED\n";
exit(1);
