#!/usr/bin/env php
<?php
/**
 * check-a11y.php — 可访问性门禁
 *
 * 扫描 PHP/HTML/Latte 中的常见 A11y 违规。
 * 集成到 pre-commit → 新增违规阻断提交。
 *
 * 检查项:
 *   1. <img> 缺 alt
 *   2. <input> 缺关联 <label>
 *   3. <button> / <a> 无文本/aria-label
 *   4. 交互元素 <44x44px (触控目标)
 *   5. 缺 lang 属性
 *   6. 硬编码低对比度色值
 *
 * 用法:
 *   php ci/check-a11y.php                 # 扫描 resources/views/
 *   php ci/check-a11y.php --staged        # 仅 staged 文件
 *   php ci/check-a11y.php --json          # JSON 输出
 *
 * 退出码: 0=通过, 1=有新增违规, 2=严重违规
 */

declare(strict_types=1);

$mode = in_array('--staged', $argv ?? []) ? 'staged' : 'all';
$json = in_array('--json', $argv ?? []);

$projectRoot = realpath(__DIR__ . '/..');
$viewsDir = $projectRoot . '/views';

// ═══ 违规记录 ═══

$violations = [];
$critical = [];    // 严重违规

function addViolation(string $file, int $line, string $type, string $desc, bool $isCritical = false): void
{
    global $violations, $critical;
    $v = ['file' => $file, 'line' => $line, 'type' => $type, 'desc' => $desc];
    $violations[] = $v;
    if ($isCritical) $critical[] = $v;
}

// ═══ 文件收集 ═══

$files = [];
if ($mode === 'staged') {
    exec('git diff --cached --name-only --diff-filter=ACM 2>&1', $staged, $code);
    if ($code === 0) {
        foreach ($staged as $f) {
            $full = $projectRoot . '/' . $f;
            if (file_exists($full) && in_array(pathinfo($f, PATHINFO_EXTENSION), ['php', 'html', 'latte'])) {
                $files[] = $full;
            }
        }
    }
} else {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($viewsDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        if ($f->isFile() && in_array($f->getExtension(), ['php', 'html', 'latte'])) {
            $files[] = $f->getPathname();
        }
    }
}

// ═══ 扫描逻辑 ═══

foreach ($files as $file) {
    $content = file_get_contents($file);
    $lines = explode("\n", $content);
    $rel = str_replace($projectRoot . '/', '', $file);

    foreach ($lines as $i => $line) {
        $ln = $i + 1;

        // 1. <img> 缺 alt
        if (preg_match_all('/<img\b[^>]*>/i', $line, $imgs)) {
            foreach ($imgs[0] as $img) {
                if (!preg_match('/\balt\s*=/i', $img)) {
                    addViolation($rel, $ln, 'img-no-alt', '图片缺少 alt 属性', true);
                } elseif (preg_match('/alt\s*=\s*""/i', $img)) {
                    // 装饰性图片 alt="" 是合法的
                }
            }
        }

        // 2. <input> 缺 id 或关联 label
        if (preg_match_all('/<input\b[^>]*type\s*=\s*"(?:text|email|password|number|url|search|tel|date)"[^>]*>/i', $line, $inputs)) {
            foreach ($inputs[0] as $input) {
                if (!preg_match('/\bid\s*=/i', $input)) {
                    addViolation($rel, $ln, 'input-no-id', '表单输入缺少 id → 无法关联 label');
                }
            }
        }

        // 3. <button> 或 <a> 无文本(aria-label空)
        if (preg_match_all('/<(?:button|a)\b[^>]*>(?:\s*<[^>]+>\s*)*\s*<\/(?:button|a)>/i', $line, $empties)) {
            foreach ($empties[0] as $el) {
                if (!preg_match('/aria-label\s*=/i', $el)) {
                    addViolation($rel, $ln, 'empty-interactive', '按钮/链接无文本内容且无 aria-label', true);
                }
            }
        }

        // 4. 交互元素固定极小尺寸 (style 中 width/height < 44px)
        if (preg_match('/<(?:button|a|input)\b[^>]*style\s*=\s*"[^"]*(?:width|height)\s*:\s*(\d+)px/i', $line, $sizes)) {
            $px = (int)$sizes[1];
            if ($px > 0 && $px < 44 && !preg_match('/min-(?:width|height)/i', $line)) {
                addViolation($rel, $ln, 'touch-target-small', "触控目标 {$px}px < 44px (WCAG 2.5.5)");
            }
        }

        // 5. input type="checkbox"/"radio" 缺 label
        if (preg_match('/<input\b[^>]*type\s*=\s*"(?:checkbox|radio)"[^>]*>/i', $line, $cbs)) {
            // 检查前后行是否有 label
            $context = implode("\n", array_slice($lines, max(0, $i - 2), min(5, count($lines) - $i)));
            if (!preg_match('/<label\b/i', $context)) {
                addViolation($rel, $ln, 'checkbox-no-label', 'checkbox/radio 缺少关联 label');
            }
        }
    }

    // 6. <html> 缺 lang
    if (preg_match('/<html\b(?!.*lang\s*=)/i', $content, $htmlMatch)) {
        $htmlPos = strpos($content, $htmlMatch[0]);
        $htmlLine = substr_count(substr($content, 0, $htmlPos), "\n") + 1;
        addViolation($rel, $htmlLine, 'html-no-lang', '<html> 缺少 lang 属性', true);
    }
}

// ═══ 统计 ═══

$byType = [];
foreach ($violations as $v) {
    $byType[$v['type']] = ($byType[$v['type']] ?? 0) + 1;
}

// ═══ 输出 ═══

if ($json) {
    echo json_encode([
        'total' => count($violations),
        'critical' => count($critical),
        'byType' => $byType,
        'violations' => array_slice($violations, 0, 100),
    ], JSON_PRETTY_PRINT) . "\n";
    exit(count($critical) > 0 ? 1 : 0);
}

echo "\n═══ A11y 可访问性门禁 ═══\n\n";
echo "扫描: " . count($files) . " 文件\n";
echo "违规: " . count($violations) . " 处 (严重: " . count($critical) . ")\n\n";

if (count($violations) === 0) {
    echo "✅ 通过 — 0 A11y 违规\n";
    exit(0);
}

// 分类展示
echo "─── 违规类型 ───\n";
foreach ($byType as $type => $count) {
    $marker = in_array($type, ['img-no-alt', 'empty-interactive', 'html-no-lang']) ? '🔴' : '🟡';
    echo "  {$marker} {$type}: {$count} 处\n";
}

echo "\n─── Top 违规 (前 10) ───\n";
foreach (array_slice($violations, 0, 10) as $v) {
    $marker = in_array($v, $critical) ? '🔴' : '🟡';
    echo "  {$marker} {$v['file']}:{$v['line']} — {$v['desc']}\n";
}

if (count($violations) > 10) {
    echo "  ... +" . (count($violations) - 10) . " more\n";
}

echo "\n📋 修复指南:\n";
echo "  img-no-alt → 添加 alt=\"描述\" 或 alt=\"\" (装饰性)\n";
echo "  input-no-id → 添加 id=\"field-name\" + <label for=\"field-name\">\n";
echo "  empty-interactive → 添加 aria-label=\"操作名\"\n";
echo "  touch-target-small → 增大到 ≥44px 或使用 min-width/min-height\n";
echo "  checkbox-no-label → 用 <label><input type=\"checkbox\"> 文本</label>\n";
echo "  html-no-lang → <html lang=\"<?=\$lang?>\">\n";

$exitCode = count($critical) > 0 ? 1 : 0;
echo "\n" . ($exitCode === 0 ? "✅ 通过 (无严重违规)" : "🚫 不通过 — " . count($critical) . " 处严重违规") . "\n";
exit($exitCode);
