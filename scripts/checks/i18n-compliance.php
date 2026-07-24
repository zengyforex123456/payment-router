<?php
/**
 * I18n Compliance Check — CI 门禁
 *
 * 检查项:
 *   1. 硬编码中英文 (HTML 标签间的裸文本)
 *   2. __() 调用引用了不存在的翻译键
 *   3. 翻译键在 zh.php 和 en.php 之间不一致
 *
 * 用法:
 *   php checks/i18n-compliance.php              # 终端报告
 *   php checks/i18n-compliance.php --json       # JSON 输出 (CI 用)
 *   php checks/i18n-compliance.php --fix-keys   # 自动补全缺失键
 *
 * 退出码: 0 = 通过, 1 = 有违规
 */

$jsonMode = in_array('--json', $argv ?? [], true);
$fixMode = in_array('--fix-keys', $argv ?? [], true);
$root = __DIR__ . '/..';

// ═══════════════════════════════════
// 1. 加载翻译键
// ═══════════════════════════════════

$zhKeys = [];
$enKeys = [];
$zhFile = $root . '/lang/zh.php';
$enFile = $root . '/lang/en.php';

if (file_exists($zhFile)) $zhKeys = require $zhFile;
if (file_exists($enFile)) $enKeys = require $enFile;

$allKeys = array_unique(array_merge(array_keys($zhKeys), array_keys($enKeys)));

// ═══════════════════════════════════
// 2. 检查翻译键一致性
// ═══════════════════════════════════

$missingInZh = array_diff(array_keys($enKeys), array_keys($zhKeys));
$missingInEn = array_diff(array_keys($zhKeys), array_keys($enKeys));

// ═══════════════════════════════════
// 3. 检查 View 文件硬编码文本
// ═══════════════════════════════════

$violations = [];

// 硬编码英文模式 (HTML 标签之间的裸英文, 排除 PHP 代码和 __() 调用)
$hardcodedPatterns = [
    // 页面标题: <h1>Something</h1> (不含 <?)
    '/<(h[1-3]|p|th|td|label|span|a|button|div|option|legend|strong|li)\b[^>]*>([A-Z][a-z]{2,}(?:\s+[A-Z][a-z]{2,}){0,10})<\/\1>/s',
    // 属性中的英文 title="Edit Something" alt="Something"
    '/\btitle="([A-Z][a-z]{2,}(?:\s+[A-Za-z]{2,}){1,8})"/',
    '/\balt="([A-Z][a-z]{2,}(?:\s+[A-Za-z]{2,}){1,8})"/',
    '/\bplaceholder="([A-Z][a-z]{2,}(?:\s+[A-Za-z]{2,}){1,12})"/',
];

// 排除模式 (不检查的)
$excludePatterns = [
    '/__\(/',          // 已使用 __() 的
    '/<\?/',           // PHP 代码
    '/\$[a-zA-Z_]/',   // PHP 变量
    '/\{[a-z_]+\}/',   // 模板变量
    '/https?:\/\//',   // URL
    '/\b(CPA|CPL|CPS|ROAS|CR|LP|URL|API|DB|CSS|HTML|PHP|JS|JSON|SQL|UTC|ID|IP|OS)\b/',  // 缩写/专有名词
    '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/', // 月份
    '/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b/', // 星期
];

// 扫描 Views
$viewFiles = array_merge(
    glob($root . '/resources/views/*.php') ?: [],
    glob($root . '/public/*.php') ?: []
);

foreach ($viewFiles as $file) {
    $basename = basename($file);
    // 跳过非 UI 文件
    if (in_array($basename, ['track.php', 'click.php', 'postback.php', 'pixel.php',
        'go.php', 'km.php', 'cloak.php', 'landing.php', 'landing-track.php',
        'api-campaign-stats.php', 'api-campaign-stats-v2.php', 'api-funnel.php',
        'api-self-heal.php', 'api-user-theme.php', 'api-postback-details.php',
        'api-campaign-tracking-link.php', 'api-fire-postback.php',
        'fire-postback-for-conversion.php', 'update-progress.php',
        'view-cron-log.php'])) continue;

    $content = file_get_contents($file);
    $lines = explode("\n", $content);

    foreach ($lines as $lineNum => $line) {
        $lineNum++; // 1-indexed
        $trimmed = trim($line);

        // 跳过空行、纯 PHP 行、注释行
        if (empty($trimmed) || preg_match('/^\s*(<\?|<?=|#|\/\/|\*)/', $trimmed)) continue;

        // 检查是否已使用 __()
        if (preg_match('/__\(/', $trimmed)) continue;

        // 检查硬编码中文 (除了 lang/ 文件夹)
        if (strpos($file, '/lang/') === false && preg_match('/[\x{4e00}-\x{9fff}]{2,}/u', $trimmed, $m)) {
            $violations[] = [
                'file' => str_replace($root . '/', '', $file),
                'line' => $lineNum,
                'type' => 'hardcoded_zh',
                'text' => mb_substr($m[0], 0, 30),
                'fix' => 'Replace with __(\'key\')',
            ];
        }

        // 检查硬编码英文 (HTML 标签间的)
        foreach ($hardcodedPatterns as $pattern) {
            if (preg_match($pattern, $trimmed, $m)) {
                $text = $m[2] ?? $m[1] ?? '';
                // 检查排除模式
                $excluded = false;
                foreach ($excludePatterns as $ep) {
                    if (preg_match($ep, $text)) { $excluded = true; break; }
                }
                if ($excluded) continue;
                // 至少 4 个字符才报警
                if (strlen($text) < 4) continue;

                $violations[] = [
                    'file' => str_replace($root . '/', '', $file),
                    'line' => $lineNum,
                    'type' => 'hardcoded_en',
                    'text' => $text,
                    'fix' => "Replace with <?=__('key')?>",
                ];
                break; // 每行只报 1 次
            }
        }
    }
}

// ═══════════════════════════════════
// 4. 检查 __() 引用缺失键
// ═══════════════════════════════════

$missingKeyViolations = [];
foreach ($viewFiles as $file) {
    $content = file_get_contents($file);
    // Exclude comment lines
    $cleaned = preg_replace('/^\s*(\/\/|#|<\?=\s*\/\/).*$/m', '', $content);
    preg_match_all("/__\(\s*'([^']+)'\s*\)/", $cleaned, $matches);
    foreach ($matches[1] as $key) {
        if (!isset($zhKeys[$key]) && !isset($enKeys[$key])) {
            $missingKeyViolations[$key] = ($missingKeyViolations[$key] ?? 0) + 1;
        }
    }
}

// ═══════════════════════════════════
// 5. 自动修复缺失键
// ═══════════════════════════════════

if ($fixMode && !empty($missingKeyViolations)) {
    foreach ($missingKeyViolations as $key => $count) {
        $zhKeys[$key] = "[TODO:zh] $key";
        $enKeys[$key] = "[TODO:en] $key";
    }
    $zhContent = "<?php\nreturn " . var_export($zhKeys, true) . ";\n";
    $enContent = "<?php\nreturn " . var_export($enKeys, true) . ";\n";
    file_put_contents($zhFile, $zhContent);
    file_put_contents($enFile, $enContent);
}

// ═══════════════════════════════════
// 输出
// ═══════════════════════════════════

$totalViolations = count($violations) + count($missingInZh) + count($missingInEn) + count($missingKeyViolations);

if ($jsonMode) {
    echo json_encode([
        'pass' => $totalViolations === 0,
        'key_count_zh' => count($zhKeys),
        'key_count_en' => count($enKeys),
        'missing_in_zh' => array_values($missingInZh),
        'missing_in_en' => array_values($missingInEn),
        'missing_keys' => array_keys($missingKeyViolations),
        'hardcoded_violations' => $violations,
        'total_violations' => $totalViolations,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo str_repeat('═', 60) . "\n";
    echo "I18n Compliance Check\n";
    echo str_repeat('═', 60) . "\n";

    echo "\n📊 Translation Keys: " . count($zhKeys) . " zh | " . count($enKeys) . " en\n";

    if ($missingInZh) {
        echo "\n❌ Missing in zh.php (" . count($missingInZh) . "):\n";
        foreach ($missingInZh as $k) echo "  - $k\n";
    }
    if ($missingInEn) {
        echo "\n❌ Missing in en.php (" . count($missingInEn) . "):\n";
        foreach ($missingInEn as $k) echo "  - $k\n";
    }
    if ($missingKeyViolations) {
        echo "\n❌ __() calls referencing missing keys:\n";
        foreach ($missingKeyViolations as $k => $c) echo "  - $k (used $c times)\n";
    }

    if ($violations) {
        echo "\n❌ Hardcoded text violations (" . count($violations) . "):\n";
        foreach ($violations as $v) {
            echo "  {$v['file']}:{$v['line']} [{$v['type']}] \"{$v['text']}\"\n";
        }
    }

    echo "\n" . str_repeat('─', 60) . "\n";
    if ($totalViolations === 0) {
        echo "✅ PASS — 0 violations\n";
    } else {
        echo "❌ FAIL — {$totalViolations} violations\n";
        if ($fixMode) echo "   (--fix-keys was applied)\n";
    }
    echo str_repeat('─', 60) . "\n";
}

exit($totalViolations === 0 ? 0 : 1);
