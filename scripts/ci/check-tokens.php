#!/usr/bin/env php
<?php
/**
 * check-tokens.php — 设计令牌强制执行门禁
 *
 * 扫描 PHP/HTML 中的硬编码色值，强制使用 CSS 变量。
 * 集成到 pre-commit → 含 #XXX 的新代码阻断提交。
 *
 * 用法:
 *   php ci/check-tokens.php              # 检查所有文件
 *   php ci/check-tokens.php --staged     # 仅检查 git staged
 *   php ci/check-tokens.php --json       # JSON 输出
 *   php ci/check-tokens.php --baseline   # 生成基线 (首次运行)
 *
 * 退出码: 0=全部通过, 1=有新增违规, 2=基线超标
 */

declare(strict_types=1);

$mode = 'all';
if (in_array('--staged', $argv ?? [])) $mode = 'staged';
if (in_array('--baseline', $argv ?? [])) $mode = 'baseline';
if (in_array('--auto-fix', $argv ?? [])) $mode = 'auto-fix';
$json = in_array('--json', $argv ?? []);
$dryRun = in_array('--dry-run', $argv ?? []);

// ═══ 令牌映射: 硬编码色值 → CSS 变量 ═══

$TOKEN_MAP = [
    // Surface
    '#ffffff' => 'var(--surface-raised)', '#fff' => 'var(--surface-raised)',
    '#f5f5f5' => 'var(--surface-base)', '#fafafa' => 'var(--surface-base)',
    '#f0f0f0' => 'var(--surface-overlay)',

    // Content
    '#333333' => 'var(--content-primary)', '#333' => 'var(--content-primary)',
    '#666666' => 'var(--content-secondary)', '#666' => 'var(--content-secondary)',
    '#999999' => 'var(--content-tertiary)', '#999' => 'var(--content-tertiary)',

    // Accent
    '#2196F3' => 'var(--accent-emphasis)',
    '#e3f2fd' => 'var(--accent-soft)',

    // Semantic
    '#4CAF50' => 'var(--success)', '#155724' => 'var(--success)',
    '#d4edda' => 'var(--success-soft)',
    '#f44336' => 'var(--danger)', '#721c24' => 'var(--danger)',
    '#ffebee' => 'var(--danger-soft)', '#f5c6cb' => 'var(--danger-soft)',
    '#ffc107' => 'var(--warning)', '#856404' => 'var(--warning)',
    '#fff3cd' => 'var(--warning-soft)',

    // Border
    '#dddddd' => 'var(--border-default)', '#ddd' => 'var(--border-default)',
    '#e0e0e0' => 'var(--border-default)',
    '#eeeeee' => 'var(--border-default)', '#eee' => 'var(--border-default)',

    // Specific Converge colors
    '#3d5a26' => 'var(--accent-emphasis)',
    '#8a4b00' => 'var(--warning)',
    '#fff8e6' => 'var(--warning-soft)',
    '#1565c0' => 'var(--accent-emphasis)',
    '#4F46E5' => 'var(--accent-emphasis)',
    '#1E3A5F' => 'var(--accent)',
    '#2563EB' => 'var(--accent-emphasis)',
    '#1d4ed8' => 'var(--accent-hover)',
    '#4338ca' => 'var(--accent-hover)',
    '#475569' => 'var(--content-secondary)',
    '#64748b' => 'var(--content-secondary)',
    '#94a3b8' => 'var(--content-tertiary)',
    '#f8fafc' => 'var(--surface-base)',
    '#f1f5f9' => 'var(--surface-overlay)',
    '#e8eaf0' => 'var(--border-default)',
    '#cbd5e1' => 'var(--border-strong)',
    '#0f172a' => 'var(--content-primary)',
    '#eef2ff' => 'var(--accent-soft)',
    '#ecfdf5' => 'var(--success-soft)',
    '#a7f3d0' => 'var(--success)',
    '#065f46' => 'var(--success)',
    '#b91c1c' => 'var(--danger)',
    '#34d399' => 'var(--success)',
    '#0369A1' => 'var(--accent-emphasis)',
    '#7C3AED' => 'var(--accent-emphasis)',
    '#F5F3FF' => 'var(--accent-soft)',
    '#EF4444' => 'var(--danger)',
];

// ═══ 豁免规则 ═══

$EXEMPT_FILES = [
    'vendor/', 'node_modules/', '.git/',
    'resources/css/design-tokens.css', 'resources/css/tailwind.min.css',
    'resources/css/app-bundle.css', 'resources/css/themes.css',
    'resources/css/settings-api.css', 'resources/css/campaign-stats.css',
    'resources/css/main.css', 'resources/css/postback-urls.css',
    'resources/css/intent-ui.css', 'resources/css/campaign-create-wizard.css',
    'resources/css/dock-layout.css', 'resources/css/mobile-campaign-stats-legacy.css',
    '.backup-inline-css/',
    'tests/', 'analysis/', '.lighthouseci/',
    'storage/', 'cache/', 'src/CopyEvaluator/',
];

$EXEMPT_PATTERNS = [
    '/\.(png|jpg|svg|gif|woff2?|ttf|eot)$/',  // 二进制
    '/\/vendor\//', '/\/node_modules\//',
    '/^\./',  // 隐藏文件
];

// PHP 动态颜色 (无法用静态 CSS 变量替代)
$LEGITIMATE_DYNAMIC = [
    '/<\?=.*\$[a-zA-Z_].*color/' => 'PHP 动态颜色表达式',
    '/<\?php.*\$[a-zA-Z_].*color/' => 'PHP 动态颜色块',
    '/rgba?\(\s*\$/' => 'PHP 变量驱动颜色',
    '/background:\s*<\?=/' => 'PHP 动态背景',
    '/color:\s*<\?=/' => 'PHP 动态前景',
];

// ═══ 扫描逻辑 ═══

function isExempt(string $file): bool
{
    global $EXEMPT_FILES, $EXEMPT_PATTERNS;
    foreach ($EXEMPT_FILES as $pfx) {
        if (str_contains($file, $pfx)) return true;
    }
    foreach ($EXEMPT_PATTERNS as $pat) {
        if (preg_match($pat, $file)) return true;
    }
    return false;
}

function isLegitimateDynamic(string $line): bool
{
    global $LEGITIMATE_DYNAMIC;
    foreach ($LEGITIMATE_DYNAMIC as $pat => $desc) {
        if (preg_match($pat, $line)) return true;
    }
    return false;
}

function scanFile(string $file): array
{
    if (!file_exists($file) || is_dir($file)) return [];
    if (isExempt($file)) return [];

    $violations = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!$lines) return [];

    foreach ($lines as $i => $line) {
        if (isLegitimateDynamic($line)) continue;

        // 搜索 #XXX 色值
        if (preg_match_all('/#([0-9a-fA-F]{3,8})\b/', $line, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $hex = '#' . $match[0];
                $col = $i + 1;
                $suggestion = $GLOBALS['TOKEN_MAP'][strtolower($hex)] ?? null;
                $violations[] = [
                    'file' => $file,
                    'line' => $col,
                    'color' => $hex,
                    'suggestion' => $suggestion,
                    'type' => 'hex',
                ];
            }
        }

        // 搜索 rgb/rgba/hsl 硬编码
        if (preg_match_all('/\b(rgba?|hsla?)\(\s*\d+/', $line, $m2, PREG_OFFSET_CAPTURE)) {
            foreach ($m2[0] as $match) {
                $violations[] = [
                    'file' => $file,
                    'line' => $i + 1,
                    'color' => $match[0],
                    'suggestion' => null,
                    'type' => 'rgb',
                ];
            }
        }

        // ═══ 架构门禁: 自写 <!DOCTYPE html> (模板绕过) ═══
        if (preg_match('/<!DOCTYPE\s+html/i', $line)) {
            // 豁免: 模板文件自身和旧页面(死代码)
            $isTemplate = str_contains($file, '_layout-head.php')
                       || str_contains($file, '_layout-foot.php')
                       || str_contains($file, '_shell.php');
            $isDeadCode = str_contains($file, 'login.php'); // 旧登录页(重定向死代码)
            if (!$isTemplate && !$isDeadCode) {
                $violations[] = [
                    'file' => $file,
                    'line' => $i + 1,
                    'color' => '<!DOCTYPE html>',
                    'suggestion' => "include '_layout-head.php' 或 PageShell()",
                    'type' => 'doctype',
                ];
            }
        }

        // ═══ 架构门禁: 自写 :root CSS 变量块 (令牌绕过) ═══
        if (preg_match('/:root\s*\{/', $line)) {
            // 豁免: 官方令牌/CSS文件
            $isTokenFile = str_contains($file, 'design-tokens.css')
                        || str_contains($file, 'tokens.css')
                        || str_contains($file, 'main.css')
                        || str_contains($file, 'ui-components.css')
                        || str_contains($file, 'themes.css');
            if (!$isTokenFile) {
                $violations[] = [
                    'file' => $file,
                    'line' => $i + 1,
                    'color' => ':root {',
                    'suggestion' => '引用 tokens.css，删除重复变量定义',
                    'type' => 'root_vars',
                ];
            }
        }

        // ═══ 架构门禁: 内联 style="" (应使用 CSS 类) ═══
        if (preg_match('/style="([^"]+)"/', $line, $m_style)) {
            // 豁免: 组件文件 (Button.php/Input.php 等原子组件内部允许)
            $isComponent = str_contains($file, 'src/UI/');
            if (!$isComponent) {
                $styleVal = $m_style[1];
                // 如果使用 var(--*) 则降级为 info，否则 warning
                $usesToken = preg_match('/var\(--/', $styleVal);
                $violations[] = [
                    'file' => $file,
                    'line' => $i + 1,
                    'color' => 'style="' . substr($styleVal, 0, 60) . '"',
                    'suggestion' => '迁移到 CSS 类 (ui-components.css)',
                    'type' => $usesToken ? 'inline_token' : 'inline_style',
                ];
            }
        }
    }

    return $violations;
}

// ═══ 主流程 ═══

$projectRoot = realpath(__DIR__ . '/..');

$files = [];
if ($mode === 'staged') {
    exec('git diff --cached --name-only --diff-filter=ACM 2>&1', $staged, $code);
    if ($code === 0) {
        foreach ($staged as $f) {
            $full = $projectRoot . '/' . $f;
            if (file_exists($full) && !isExempt($full) && pathinfo($f, PATHINFO_EXTENSION) === 'php') {
                $files[] = $full;
            }
        }
    }
} else {
    // 仅扫描应用代码目录
    $scanDirs = [
        $projectRoot . '/views',
        $projectRoot . '/public',
        $projectRoot . '/resources/css',
        $projectRoot . '/src',
        $projectRoot . '/templates',
    ];
    foreach ($scanDirs as $dir) {
        if (!is_dir($dir)) continue;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $f) {
            if ($f->isFile() && in_array($f->getExtension(), ['php', 'html', 'latte', 'css'])) {
                $full = $f->getPathname();
                if (!isExempt($full)) $files[] = $full;
            }
        }
    }
}

// 扫描
$allViolations = [];
foreach ($files as $file) {
    $v = scanFile($file);
    if ($v) {
        $allViolations = array_merge($allViolations, $v);
    }
}

// 统计
$hexCount    = count(array_filter($allViolations, fn($v) => $v['type'] === 'hex'));
$rgbCount    = count(array_filter($allViolations, fn($v) => $v['type'] === 'rgb'));
$doctypeCnt  = count(array_filter($allViolations, fn($v) => $v['type'] === 'doctype'));
$rootVarsCnt = count(array_filter($allViolations, fn($v) => $v['type'] === 'root_vars'));
$inlineCnt   = count(array_filter($allViolations, fn($v) => $v['type'] === 'inline_style'));
$inlineTokCnt= count(array_filter($allViolations, fn($v) => $v['type'] === 'inline_token'));
$archCount   = $doctypeCnt + $rootVarsCnt + $inlineCnt;
$fixableCount = count(array_filter($allViolations, fn($v) => $v['suggestion'] !== null));

// 输出
if ($json) {
    echo json_encode([
        'total' => count($allViolations),
        'hex' => $hexCount,
        'rgb' => $rgbCount,
        'doctype' => $doctypeCnt,
        'root_vars' => $rootVarsCnt,
        'architecture' => $archCount,
        'fixable' => $fixableCount,
        'violations' => array_map(fn($v) => [
            'file' => str_replace($projectRoot . '/', '', $v['file']),
            'line' => $v['line'],
            'color' => $v['color'],
            'suggestion' => $v['suggestion'],
        ], $allViolations),
    ], JSON_PRETTY_PRINT) . "\n";
    exit(count($allViolations) > 0 ? 1 : 0);
}

// 终端输出
if ($mode === 'baseline') {
    echo "📐 设计令牌基线\n";
    echo "总扫描: " . count($files) . " 文件\n";
    echo "现有硬编码色值: " . count($allViolations) . " 处\n";
    echo "可自动修复: {$fixableCount} 处\n\n";

    // 按文件分组
    $byFile = [];
    foreach ($allViolations as $v) {
        $rel = str_replace($projectRoot . '/', '', $v['file']);
        $byFile[$rel][] = $v;
    }
    arsort($byFile);
    foreach (array_slice($byFile, 0, 15) as $file => $vs) {
        echo "  {$file}: " . count($vs) . " 处\n";
        foreach (array_slice($vs, 0, 3) as $v) {
            $fix = $v['suggestion'] ? " → {$v['suggestion']}" : '';
            echo "    L{$v['line']}: {$v['color']}{$fix}\n";
        }
    }
    exit(0);
}

// ═══ Auto-Fix 模式 ═══

if ($mode === 'auto-fix') {
    $fixableViolations = array_filter($allViolations, fn($v) => $v['suggestion'] !== null);
    $byFile = [];
    foreach ($fixableViolations as $v) {
        $byFile[$v['file']][] = $v;
    }

    if (empty($byFile)) {
        echo "✅ 无可自动修复的色值\n";
        exit(0);
    }

    $backupDir = $projectRoot . '/.backup-token-fix';
    if (!$dryRun && !is_dir($backupDir)) mkdir($backupDir, 0755, true);

    $fixed = 0;
    $failed = 0;

    foreach ($byFile as $file => $violations) {
        if (!file_exists($file)) continue;
        $content = file_get_contents($file);
        $original = $content;
        $fileFixed = 0;

        // 按行号倒序处理，避免行号偏移
        usort($violations, fn($a, $b) => $b['line'] - $a['line']);

        foreach ($violations as $v) {
            if (!$v['suggestion']) continue;
            $search = $v['color'];
            // 在指定行中精确定位并替换
            $lines = explode("\n", $content);
            $idx = $v['line'] - 1;
            if (!isset($lines[$idx])) continue;

            $oldLine = $lines[$idx];
            // 仅在 CSS 属性值或 style 属性中替换
            $count = 0;
            $newLine = str_ireplace($search, $v['suggestion'], $oldLine, $count);
            if ($count > 0) {
                $lines[$idx] = $newLine;
                $content = implode("\n", $lines);
                $fileFixed += $count;
            }
        }

        if ($fileFixed > 0) {
            if ($dryRun) {
                $rel = str_replace($projectRoot . '/', '', $file);
                echo "  🔍 {$rel}: {$fileFixed} 处 (dry-run)\n";
                $fixed += $fileFixed;
            } else {
                // 备份
                $backupPath = $backupDir . '/' . str_replace(['/', '\\', ':'], '_', $file);
                file_put_contents($backupPath, $original);

                // 写入
                file_put_contents($file, $content);

                // PHP 语法验证
                if (str_ends_with($file, '.php')) {
                    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
                    if ($code !== 0) {
                        // 回滚
                        file_put_contents($file, $original);
                        $rel = str_replace($projectRoot . '/', '', $file);
                        echo "  ❌ {$rel}: 修复导致语法错误，已回滚\n";
                        $failed += $fileFixed;
                        continue;
                    }
                }

                $rel = str_replace($projectRoot . '/', '', $file);
                echo "  ✅ {$rel}: {$fileFixed} 处\n";
                $fixed += $fileFixed;
            }
        }
    }

    echo "\n─── Auto-Fix 完成 ───\n";
    echo "修复: {$fixed} 处 | 失败: {$failed} 处";
    if ($dryRun) echo " (dry-run)";
    if (!$dryRun && $fixed > 0) echo "\n备份: {$backupDir}";
    echo "\n";
    exit($failed > 0 ? 1 : 0);
}

// 门禁模式
echo "\n═══ 设计令牌门禁 ═══\n\n";
echo "扫描: " . count($files) . " 文件\n";
echo "色值: {$hexCount} #hex, {$rgbCount} rgb()\n";
if ($archCount > 0 || $inlineTokCnt > 0) {
    echo "架构: {$doctypeCnt} <!DOCTYPE>, {$rootVarsCnt} :root";
    if ($inlineCnt > 0) echo ", {$inlineCnt} inline style";
    if ($inlineTokCnt > 0) echo " (+{$inlineTokCnt} token-ok inline)";
    echo "\n";
}
echo "可自动修复: {$fixableCount}\n\n";

if (count($allViolations) === 0) {
    echo "✅ 通过 — 0 硬编码色值\n";
    exit(0);
}

// 展示违规 (按文件)
$byFile = [];
foreach ($allViolations as $v) {
    $rel = str_replace($projectRoot . '/', '', $v['file']);
    $byFile[$rel][] = $v;
}

foreach (array_slice($byFile, 0, 10) as $file => $vs) {
    $hasNew = false; // 简化: 都标记
    $marker = '⚠️';
    echo "{$marker} {$file}: " . count($vs) . " 处\n";
    foreach (array_slice($vs, 0, 3) as $v) {
        $fix = $v['suggestion'] ? " → {$v['suggestion']}" : ' (无建议)';
        echo "   L{$v['line']}: {$v['color']}{$fix}\n";
    }
    if (count($vs) > 3) echo "   ... +" . (count($vs) - 3) . " more\n";
}

if ($mode === 'staged' && count($allViolations) === 0) {
    echo "✅ 令牌门禁通过 — staged 文件无新增硬编码色值\n";
    exit(0);
}

echo "\n🚫 令牌门禁不通过: " . count($allViolations) . " 处硬编码色值\n";
echo "修复: 替换为 CSS 变量 (var(--xxx)) 或添加豁免规则\n";
echo "提示: 使用 --staged 仅检查 git staged 文件 (pre-commit)\n";

if ($fixableCount > 0) {
    echo "\n💡 {$fixableCount} 处可自动识别 → 运行 --auto-fix 自动修复\n";
}

exit(count($allViolations) > 0 ? 1 : 0);
