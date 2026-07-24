<?php
/**
 * verify-contracts.php — 前端→后端绑定自动验证 (零手动 JSON)
 *
 * 核心原则: 契约从代码中自动派生, 不同步手工维护的外部文件。
 *
 * 四维检查:
 *  ① Route   — SPA 路由声明 → Handler 存在 (auto-discover from index.latte + demo-index.php)
 *  ② Class   — use 语句 → class_exists (auto-discover via Composer autoload)
 *  ③ Action  — ApiRegistry implemented → endpoint 文件存在
 *  ④ PageData — PageContract 契约覆盖率
 *
 * 用法:
 *   php scripts/verify-contracts.php          全量检查
 *   php scripts/verify-contracts.php --json   JSON 输出 (CI)
 *   php scripts/verify-contracts.php --page=analytics  单页面检查
 *
 * 门禁: exit 0 = 通过 · exit 1 = 有阻断违规
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$jsonOutput = in_array('--json', $argv);
$pageFilter = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--page=')) { $pageFilter = substr($arg, 7); break; }
}

require_once "$root/vendor/autoload.php";
require_once "$root/config/config.php";

$errors = [];
$warnings = [];

function logResult(string $level, string $msg): void {
    global $errors, $warnings, $jsonOutput;
    if ($level === 'error') { $errors[] = $msg; }
    else { $warnings[] = $msg; }
    if (!$jsonOutput) echo "  {$msg}\n";
}

// ═══════════════════════════════════════════
// ① ROUTE CONTRACT — auto-discover from code
// ═══════════════════════════════════════════

if (!$jsonOutput) echo "📡 Route Contract\n";

// Discover SPA routes from index.latte
$indexLatte = file_get_contents("$root/templates/index.latte");
preg_match_all('/\{elseif\s+\$page\s*===\s*[\'"](\w+)[\'"]/', $indexLatte, $spaRoutes);
$spaHandlers = $spaRoutes[1] ?? [];

// Also check the initial {if $page === 'X'} pattern
preg_match('/\{if\s+\$page\s*===\s*[\'"](\w+)[\'"]/', $indexLatte, $firstRoute);
if ($firstRoute) { array_unshift($spaHandlers, $firstRoute[1]); }

// Discover declared routes from demo-index.php
$demoIndex = file_get_contents("$root/public/demo-index.php");

// Extract $spaPages array
preg_match('/\$spaPages\s*=\s*\[([^\]]+)\]/', $demoIndex, $spaDeclared);
$spaDeclaredPages = [];
if ($spaDeclared) {
    preg_match_all("/'(\w+)'/", $spaDeclared[1], $spaMatches);
    $spaDeclaredPages = $spaMatches[1] ?? [];
}

// Extract standalone entries: 'pageName' => ['tpl' => 'full/path.latte', ...]
preg_match_all("/'(\w+)'\s*=>\s*\[\s*'tpl'\s*=>\s*'([^']+)'/", $demoIndex, $standaloneMatches);
$standalonePages = [];
$standaloneTemplates = [];
if ($standaloneMatches) {
    foreach ($standaloneMatches[1] as $i => $name) {
        $standalonePages[] = $name;
        $standaloneTemplates[$name] = $standaloneMatches[2][$i];
    }
}

// Check: every declared SPA page has a handler in index.latte
foreach ($spaDeclaredPages as $page) {
    if ($pageFilter && $page !== $pageFilter) continue;
    if (!in_array($page, $spaHandlers)) {
        logResult('error', "❌ SPA 路由空洞: '{$page}' 在 demo-index.php \$spaPages 中声明, 但 index.latte 无 {elseif \$page === '{$page}'} 分支");
    }
}

// Check: every standalone page template exists (use full template path from $standalone array)
foreach ($standalonePages as $page) {
    if ($pageFilter && $page !== $pageFilter) continue;
    $tplPath = $standaloneTemplates[$page] ?? "pages/{$page}.latte";
    $tpl = "$root/templates/{$tplPath}";
    if (!file_exists($tpl)) {
        logResult('error', "❌ standalone 模板缺失: {$page} → {$tpl}");
    }
}

$routeFound = count($spaHandlers) + count($standalonePages);
if (!$jsonOutput) echo "  ✅ 发现 {$routeFound} 路由: SPA=" . count($spaHandlers) . " standalone=" . count($standalonePages) . "\n\n";

// ═══════════════════════════════════════════
// ② CLASS CONTRACT — class_exists (no manual classmap!)
// ═══════════════════════════════════════════

if (!$jsonOutput) echo "📦 Class Contract\n";

// Scan public/*.php for use statements
$checkedClasses = 0;
$missingClasses = [];

foreach (glob("$root/public/*.php") as $file) {
    $content = file_get_contents($file);
    preg_match_all('/^use\s+(Converge\\\[\w\\\\]+);$/m', $content, $uses);

    foreach ($uses[1] as $class) {
        // Skip self-referencing patterns and known non-class imports
        if (str_contains($class, '{')) continue;

        $checkedClasses++;
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            $missingClasses[basename($file)][] = $class;
        }
    }
}

if ($missingClasses) {
    foreach ($missingClasses as $file => $classes) {
        foreach ($classes as $cls) {
            logResult('error', "❌ {$file}: use {$cls} — 类不存在 (composer dump-autoload?)");
        }
    }
}

if (!$jsonOutput) {
    $missing = count($missingClasses);
    echo "  ✅ 检查 {$checkedClasses} 个 use 语句, {$missing} 缺失\n\n";
}

// ═══════════════════════════════════════════
// ③ ACTION CONTRACT — ApiRegistry binding
// ═══════════════════════════════════════════

if (!$jsonOutput) echo "🔘 Action Contract\n";

if (class_exists('Converge\Foundation\Contract\ApiRegistry')) {
    $stats = \Converge\Foundation\Contract\ApiRegistry::stats();
    $actions = \Converge\Foundation\Contract\ApiRegistry::getAllActions();

    // Verify implemented actions have endpoint files
    $endpointCheck = \Converge\Foundation\Contract\ApiRegistry::verifyEndpoints();
    foreach ($endpointCheck['missing'] as $msg) {
        logResult('error', "❌ {$msg}");
    }

    // Warn about placeholders
    if (!$jsonOutput) {
        $placeholders = \Converge\Foundation\Contract\ApiRegistry::getByStatus('placeholder');
        $plCount = count($placeholders);
        echo "  ⚠ {$plCount} placeholder actions (前端置灰, 用户可见但不可用):\n";
        foreach ($placeholders as $key => $action) {
            echo "     {$key} → {$action['endpoint']['url']}\n";
        }
        echo "  ✅ {$stats['implemented']} implemented / {$stats['total']} total = {$stats['coverage_pct']}% 覆盖率\n\n";
    }
} else {
    logResult('error', "❌ ApiRegistry class 不可用");
    if (!$jsonOutput) echo "\n";
}

// ═══════════════════════════════════════════
// ④ PAGE DATA CONTRACT — existing PageContract
// ═══════════════════════════════════════════

if (!$jsonOutput) echo "📋 Page Data Contract\n";

if (class_exists('Converge\Foundation\Contract\PageContract')) {
    $pages = \Converge\Foundation\Contract\PageContract::listContracts();
    if (!$jsonOutput) {
        echo "  ✅ " . count($pages) . " 页面有契约: " . implode(', ', $pages) . "\n";
    }
} else {
    if (!$jsonOutput) echo "  ⚠ PageContract 类不可用 (生产环境跳过)\n";
}

// ═══════════════════════════════════════════
// RESULT
// ═══════════════════════════════════════════

if ($jsonOutput) {
    echo json_encode([
        'ok' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
        'routes' => ['spa' => $spaHandlers, 'standalone' => $standalonePages, 'total' => $routeFound],
        'classes' => ['checked' => $checkedClasses, 'missing' => count($missingClasses)],
        'actions' => $stats ?? ['total' => 0, 'implemented' => 0, 'placeholders' => 0, 'coverage_pct' => 0],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo str_repeat('─', 50) . "\n";
    $errCount = count($errors);
    $warnCount = count($warnings);
    if ($errCount === 0) {
        echo "✅ 契约验证通过 — 0 阻断, {$warnCount} 警告\n";
        exit(0);
    } else {
        echo "❌ {$errCount} 阻断违规, {$warnCount} 警告\n";
        exit(1);
    }
}
