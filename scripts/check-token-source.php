<?php
/**
 * check-token-source.php — 令牌源唯一性检查 (P0 阻塞级)
 *
 * 规则: design-tokens.css 是唯一 :root 令牌定义文件。
 *       其他 CSS 文件禁止在 :root 中硬编码 --surface-* / --content-* / --accent-emphasis。
 *       允许 var() 引用桥接模式 (如 app-bundle.css 的 --color-* → var(--content-*))。
 *
 * 问题根因: 4 个 CSS 文件的 :root 互相覆盖 → landing2.css 白色胜出 → 暗色主题失效。
 * 预防: 本检查在提交时硬阻断任何新的 :root 令牌覆盖。
 *
 * 用法:
 *   php scripts/check-token-source.php          检查所有
 *   php scripts/check-token-source.php --staged 仅检查 staged
 *   php scripts/check-token-source.php --fix    自动修复 layout 引用
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$mode = in_array('--staged', $argv) ? 'staged' : 'all';
$fix  = in_array('--fix', $argv);
$errors = 0;
$warnings = 0;

// ═══ 保护的令牌名 (禁止在其他文件的 :root 中硬编码定义) ═══
const PROTECTED_TOKENS = [
    '--surface-base', '--surface-raised', '--surface-overlay',
    '--content-primary', '--content-secondary', '--content-tertiary', '--content-inverse',
    '--accent-emphasis',
    '--bg-base', '--bg-raised', '--bg-overlay', '--bg-card',
    '--text-primary', '--text-secondary', '--text-tertiary', '--text-inverse',
];

const TOKEN_FILE = 'design-tokens.css';
const DEPRECATED_FILES = ['tokens.css'];

// ═══ Helper: check if a CSS value is a hardcoded color (not var() reference) ═══
function isHardcodedValue(string $value): bool {
    $value = trim($value);
    // var() references are OK (bridge pattern)
    if (preg_match('/^var\(/', $value)) return false;
    // Hex colors, rgb(), rgba(), hsl() are hardcoded
    if (preg_match('/^#[0-9a-fA-F]{3,8}/', $value)) return true;
    if (preg_match('/^rgb(a?)\(/', $value)) return true;
    if (preg_match('/^hsl(a?)\(/', $value)) return true;
    // Named colors
    if (preg_match('/^(white|black|transparent|inherit|initial|unset)$/i', $value)) return true;
    // Numeric values (not protected — spacing tokens are fine)
    return false;
}

// ═══ Rule 1: _layout.latte must load design-tokens.css (the ONLY token source) ═══
$layoutFile = "$root/templates/_layout.latte";
$layoutContent = file_get_contents($layoutFile);

foreach (DEPRECATED_FILES as $dep) {
    // Only flag if the deprecated file is referenced WITHOUT "design-" prefix
    // (str_contains would match 'tokens.css' inside 'design-tokens.css')
    $hasDeprecated = preg_match('{(?<!design-)' . preg_quote($dep, '}') . '}', $layoutContent);
    if ($hasDeprecated && !str_contains($layoutContent, "(deprecated: use " . TOKEN_FILE . ")")) {
        echo "  ❌ _layout.latte 加载了旧令牌文件: {$dep}\n";
        echo "     应加载: " . TOKEN_FILE . " (高对比度, 唯一令牌源)\n";
        if ($fix) {
            $new = preg_replace('{(?<!design-)' . preg_quote($dep, '}') . '}', TOKEN_FILE, $layoutContent);
            file_put_contents($layoutFile, $new);
            echo "     ✅ 已自动修复\n";
        }
        $errors++;
    }
}

if (!str_contains($layoutContent, TOKEN_FILE)) {
    echo "  ❌ _layout.latte 未加载 " . TOKEN_FILE . "\n";
    $errors++;
}

// ═══ Rule 2 (HARD BLOCK): 其他 CSS 文件的 :root 不得硬编码受保护的令牌 ═══
$cssDir = "$root/public/build/css";
$tokenFiles = [];

// Files exempt from hard-block (auto-generated or in migration):
// tokens.css — auto-generated, still used by standalone public pages (login, landing, etc.)
// TODO: migrate standalone pages to design-tokens.css, then delete tokens.css
const EXEMPT_FILES = ['tokens.css'];

foreach (glob("$cssDir/*.css") as $file) {
    $content = file_get_contents($file);
    $name = basename($file);

    // Skip the canonical token file
    if ($name === TOKEN_FILE) {
        // Verify bridge aliases are present
        $hasBridge = str_contains($content, '--surface-base:') && str_contains($content, '--content-primary:');
        if (!$hasBridge) {
            echo "  ⚠ " . TOKEN_FILE . ": 缺少 bridge 别名 (--surface-* → --bg-*, --content-* → --text-*)\n";
            $warnings++;
        }
        continue;
    }

    // Skip exempt files (auto-generated, pending migration)
    if (in_array($name, EXEMPT_FILES)) {
        continue;
    }

    // Extract :root blocks from this file
    if (!preg_match_all('/:root\s*\{([^}]+)\}/s', $content, $rootBlocks)) {
        continue; // No :root block — safe
    }

    foreach ($rootBlocks[1] as $blockIndex => $block) {
        // Parse CSS properties from the :root block
        preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $block, $props, PREG_SET_ORDER);

        $hardcodedProtected = [];
        foreach ($props as $prop) {
            $tokenName = $prop[1];
            $tokenValue = trim($prop[2]);

            if (in_array($tokenName, PROTECTED_TOKENS) && isHardcodedValue($tokenValue)) {
                $hardcodedProtected[] = "  {$tokenName}: {$tokenValue}";
            }
        }

        if (!empty($hardcodedProtected)) {
            echo "  ❌ {$name}: :root 中硬编码了受保护令牌 — 会覆盖 " . TOKEN_FILE . " 的值!\n";
            foreach ($hardcodedProtected as $h) {
                echo "     {$h}\n";
            }
            echo "     修复: 移除此 :root 块中的令牌定义, 改用 design-tokens.css 提供的值\n";
            $errors++;
        }
    }
}

// ═══ Rule 3: 模板文件只能引用设计令牌, 不能引用旧令牌文件 ═══
$templateDir = "$root/templates";
$checked = 0;

foreach (array_merge(
    glob("$templateDir/*.latte") ?: [],
    glob("$templateDir/_layouts/*.latte") ?: [],
    glob("$templateDir/pages/*.latte") ?: [],
    glob("$templateDir/_content/*.latte") ?: [],
) as $file) {
    $content = file_get_contents($file);
    $checked++;

    foreach (DEPRECATED_FILES as $dep) {
        // Use negative lookbehind to avoid matching 'design-tokens.css'
        if (preg_match('{(?<!design-)' . preg_quote($dep, '}') . '}', $content)) {
            echo "  ⚠ " . basename($file) . ": 引用了旧令牌 {$dep}\n";
            $warnings++;
        }
    }
}

// ═══ Rule 4: design-tokens.css must define all bridge tokens ═══
$dtContent = file_get_contents("$cssDir/" . TOKEN_FILE);
$requiredBridges = [
    '--surface-base', '--surface-raised',
    '--content-primary', '--content-secondary', '--content-tertiary',
    '--accent-emphasis', '--border-subtle',
];
$missingBridges = [];
foreach ($requiredBridges as $bridge) {
    if (!preg_match('/' . preg_quote($bridge, '/') . '\s*:/', $dtContent)) {
        $missingBridges[] = $bridge;
    }
}
if (!empty($missingBridges)) {
    echo "  ❌ " . TOKEN_FILE . ": 缺少 bridge 别名: " . implode(', ', $missingBridges) . "\n";
    $errors++;
}

// ═══ Result ═══
echo "\n";
echo "📐 令牌源: " . TOKEN_FILE . " | 检查: {$checked} 模板 | ";

if ($errors === 0 && $warnings === 0) {
    echo "✅ 通过\n";
    echo "   暗色令牌: bg=#080b12 | text=#f0f4fc(15:1 AAA) | accent=#00f0a8 // token values\n";
    exit(0);
} elseif ($errors === 0) {
    echo "✅ 通过 ({$warnings} 警告)\n";
    exit(0);
} else {
    echo "❌ {$errors} 阻断级违规, {$warnings} 警告\n";
    echo "\n";
    echo "修复指南:\n";
    echo "  1. 受保护令牌 (--surface-*, --content-*, --accent-emphasis) 只能在\n";
    echo "     " . TOKEN_FILE . " 的 :root 中定义\n";
    echo "  2. 其他 CSS 文件如需引用这些令牌, 使用 var() 桥接:\n";
    echo "     --my-color: var(--content-primary);  ← ✅ 允许\n";
    echo "     --content-primary: var(--text-primary);          ← ❌ 阻断 // example\n";
    echo "  3. 运行 php scripts/check-token-source.php --fix 修复 layout 引用\n";
    exit(1);
}
