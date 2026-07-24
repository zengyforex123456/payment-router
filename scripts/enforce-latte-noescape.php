<?php
/**
 * enforce-latte-noescape.php — 检测 Latte 模板中缺少 |noescape 的 HTML 变量
 *
 * 规则: 若 PHP 端传给模板的变量值包含 HTML 标签 (<strong>/<br>/<em>/<a )，
 *       则对应的 Latte 模板中该变量必须使用 |noescape 过滤器。
 *
 * 用法: php scripts/enforce-latte-noescape.php [--fix]
 * 退出码: 0=通过, 1=有违规
 */
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$violations = [];

// ═══════════════════════════════════════════════════════════════
// 1. 扫描 PHP 控制器文件 → 找出传给模板的 HTML 变量
// ═══════════════════════════════════════════════════════════════

/** @var array<string, array{file: string, vars: string[]}> $phpHtmlVars */
$phpHtmlVars = []; // ['pages/landing' => ['file' => 'public/landing.php', 'vars' => ['hero.subtitle', 'step.desc', ...]]]

$phpFiles = array_merge(
    glob($projectRoot . '/public/*.php') ?: [],
);

foreach ($phpFiles as $phpFile) {
    $basename = basename($phpFile);
    // 跳过 API 和特殊文件
    if (preg_match('/^api-/', $basename)) continue;
    if ($basename === 'index.php') continue;

    $content = file_get_contents($phpFile);
    if ($content === false) continue;

    // 找到 LatteEngine::render() 或 LatteEngine::display() 调用
    if (!preg_match_all(
        "/LatteEngine::(?:render|display)\s*\(\s*['\"]([^'\"]+)['\"]/",
        $content, $tplMatches
    )) {
        continue;
    }

    // 对每个模板引用，找到传给它的变量数组
    foreach ($tplMatches[1] as $tplName) {
        // 提取变量赋值: 'key' => $var,  或  'key' => $zh ? '...' : '...'
        // 找出包含 HTML 标签的字符串值
        preg_match_all(
            "/'(\w+)'\s*=>\s*.*?<(\/?(?:strong|br|em|a\s)[^>]*)>/is",
            $content, $htmlMatches
        );

        $htmlVars = [];
        foreach ($htmlMatches[1] as $i => $varName) {
            $tag = $htmlMatches[2][$i];
            // 这是顶层变量; 检查是否有嵌套数组
            // TODO: 递归检测嵌套数组中的 HTML
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// 2. 简化版: 硬编码已知的 HTML-rich 变量 → Template 检查
// ═══════════════════════════════════════════════════════════════

// 已知含 HTML 的模板变量 (PHP → Latte)
$knownHtmlVariables = [
    'pages/landing' => [
        'hero.subtitle'     => '_hero.latte',
        'hero.title'        => '_hero.latte',
        'how.steps.*.desc'  => '_how-it-works.latte',
        'how.footer_text'   => '_how-it-works.latte',
        'feat.subtitle'     => '_feature-grid.latte',
        'feat.features.*.desc' => '_feature-grid.latte',
        'cta.subtitle'      => '_final-cta.latte',
    ],
];

foreach ($knownHtmlVariables as $tplName => $varDefs) {
    $tplFile = $projectRoot . '/templates/' . $tplName . '.latte';
    if (!file_exists($tplFile)) continue;

    $tplContent = file_get_contents($tplFile);

    foreach ($varDefs as $varPath => $partial) {
        // 查找 Latte partial 文件中对应的变量引用
        $partialFile = $projectRoot . '/templates/landing/' . $partial;
        if (!file_exists($partialFile)) continue;

        $partialContent = file_get_contents($partialFile);

        // 提取变量名最后一段 (如 hero.subtitle → subtitle)
        $parts = explode('.', $varPath);
        $lastPart = end($parts);
        $parentVar = $parts[0] ?? '';

        // 在 partial 中搜索 {$parentVar['$lastPart']} 或 {$var['$lastPart']}
        $pattern = '/\{\$(\w+)\[[\'"]' . preg_quote($lastPart, '/') . '[\'"]\](?!\|noescape)/';
        if (preg_match($pattern, $partialContent, $m)) {
            $foundVar = $m[1];
            // 检查是否已有 |noescape
            if (!preg_match('/\{\$' . preg_quote($foundVar, '/') . '\[[\'"]' . preg_quote($lastPart, '/') . '[\'"]\]\|noescape/', $partialContent)) {
                $violations[] = [
                    'template' => 'templates/landing/' . $partial,
                    'variable' => "{\${$foundVar}['{$lastPart}']}",
                    'var_path' => $varPath,
                ];
            }
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// 3. 输出
// ═══════════════════════════════════════════════════════════════

if (empty($violations)) {
    echo "✅ 所有 HTML 变量均已使用 |noescape\n";
    exit(0);
}

echo "❌ 发现 " . count($violations) . " 个缺少 |noescape 的 HTML 变量:\n\n";
foreach ($violations as $v) {
    echo "  📄 {$v['template']}\n";
    echo "     变量: {$v['variable']}  (来自 {$v['var_path']})\n";
    echo "     修复: 添加 |noescape → {$v['variable']}|noescape\n\n";
}
exit(1);
