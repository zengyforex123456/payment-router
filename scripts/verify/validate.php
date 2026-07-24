<?php

declare(strict_types=1);

/**
 * validate.php — Converge 统一验证门 (zhiceos validate_pipeline 的 PHP 映射)
 *
 * 一道门跑完: 验收套件(阻断) + 合规扫描(建议) + PHP 语法 lint(阻断)。
 * 跨平台(纯 PHP,不依赖 bash),本地与服务器同一入口。散落的 verify 脚本
 * 此前无任何门统一跑 → 本门收口。集成层(需DB的 scripts/verify.php)不在此,留服务器。
 *
 * 两级门(同 pre-commit hook 原则「只阻断新增,不阻断历史债」):
 *   ① 验收套件 verify-*.php  = 硬阻断. FAIL=具体行为回归, 必须绿.
 *   ② 合规扫描 checks/*.php   = 建议级. 全树历史债计数, 报数不阻断.
 *   ③ 语法 lint              = 硬阻断. 语法错误永远真失败.
 *
 * 用法:
 *   php validate.php            # 全量: 验收 + 合规 + 全树语法 lint
 *   php validate.php --fast     # 跳过全树 lint(只跑验收+合规)
 *   php validate.php --staged   # 只 lint staged 的 PHP(pre-commit 用)
 *   php validate.php --e2e       # E2E 视觉回归 + Token 断言 (需要浏览器 + 运行中的服务器)
 *
 * 退出码: 0 = 阻断级全绿, 1 = 验收或语法失败(建议级不影响退出码)。
 */

$root   = __DIR__;
$php    = PHP_BINARY;
$args   = array_slice($argv, 1);
$fast   = in_array('--fast', $args, true);
$staged = in_array('--staged', $args, true);
$e2e    = in_array('--e2e', $args, true);

/** @return array{0:int,1:list<string>} [exitCode, outputLines] */
function runCmd(string $cmd): array
{
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, $out];
}

/** 从输出里抽 "PASS=N FAIL=M" 摘要行 */
function tailStat(array $out): string
{
    foreach (array_reverse($out) as $line) {
        if (preg_match('/PASS\s*=\s*\d+|FAIL\s*=\s*\d+/u', $line) === 1) {
            return trim($line);
        }
    }
    return '';
}

$rel = static fn (string $p): string => str_replace($root . DIRECTORY_SEPARATOR, '', str_replace('/', DIRECTORY_SEPARATOR, $p));

$totalPass = 0;
$totalFail = 0;
$advisoryWarn = 0;
$failed = [];

echo "═══ Converge 统一验证门 ═══\n\n";

// ─── ① 验收套件(阻断): 纯函数 verify-*.php, FAIL=真回归 ───
echo "① 验收套件 (纯函数断言 · 阻断)\n";
$acceptance = glob($root . '/verify-*.php') ?: [];
sort($acceptance);
foreach ($acceptance as $script) {
    $name = $rel($script);
    [$code, $out] = runCmd(escapeshellarg($php) . ' ' . escapeshellarg($script));
    $tail = tailStat($out);
    if ($code === 0) {
        $totalPass++;
        echo "  ✅ {$name}" . ($tail !== '' ? "  ({$tail})" : '') . "\n";
    } else {
        $totalFail++;
        $failed[] = $name;
        echo "  ❌ {$name}" . ($tail !== '' ? "  ({$tail})" : '') . "\n";
        foreach (array_slice($out, -4) as $l) {
            echo "       {$l}\n";
        }
    }
}

// ─── ② 合规扫描(建议): checks/*.php 是历史债计数, 报数不阻断 ───
echo "\n② 合规扫描 (历史债 · 建议级, 不阻断)\n";
foreach (glob($root . '/checks/*.php') ?: [] as $script) {
    $name = $rel($script);
    [$code, $out] = runCmd(escapeshellarg($php) . ' ' . escapeshellarg($script));
    if ($code === 0) {
        echo "  ✅ {$name}\n";
    } else {
        $advisoryWarn++;
        $summary = '';
        foreach (array_reverse($out) as $l) {
            if (preg_match('/violation|违规|FAIL/ui', $l) === 1) {
                $summary = trim($l);
                break;
            }
        }
        echo "  ⚠️  {$name}" . ($summary !== '' ? "  ({$summary})" : '') . " — 建议修复, 不阻断\n";
    }
}

// ─── ③ PHP 语法 lint(阻断) ───
$mode = $staged ? ' (staged)' : ($fast ? '' : ' (全树)');
echo "\n③ PHP 语法 lint{$mode}\n";
if ($fast) {
    echo "  ⏭️  --fast 跳过\n";
} else {
    $files = collectPhpFiles($root, $staged);
    $lintFail = 0;
    foreach ($files as $f) {
        if (!is_file($f)) {
            continue;
        }
        [$code] = runCmd(escapeshellarg($php) . ' -l ' . escapeshellarg($f));
        if ($code !== 0) {
            $lintFail++;
            $failed[] = 'lint:' . $rel($f);
            echo "  ❌ 语法错误: " . $rel($f) . "\n";
        }
    }
    if ($lintFail === 0) {
        $totalPass++;
        echo "  ✅ " . count($files) . " 个 PHP 文件语法通过\n";
    } else {
        $totalFail += $lintFail;
    }
}

// ─── ④ 链接/依赖验证 (阻断): check-links.php ───
echo "\n④ 链接完整性 (page-registry 交叉验证 · 阻断)\n";
$checkLinks = __DIR__ . '/../check-links.php';
if (file_exists($checkLinks)) {
    [$code, $lines] = runCmd($php . ' ' . escapeshellarg($checkLinks));
    foreach ($lines as $l) echo "  {$l}\n";
    if ($code === 0) { $totalPass++; echo "  ✅ 所有链接文件存在\n"; }
    else { $totalFail++; }
} else {
    echo "  ⚠️ check-links.php 缺失, 跳过\n";
}

// ─── ⑤ E2E 视觉回归 (仅 --e2e, 需要浏览器) ───
if ($e2e) {
    echo "\n⑤ E2E 视觉回归 (Playwright · 需要运行中的服务器)\n";
    $e2eScript = __DIR__ . '/../run-e2e.sh';
    if (file_exists($e2eScript)) {
        [$code, $lines] = runCmd("bash " . escapeshellarg($e2eScript));
        foreach ($lines as $l) echo "  {$l}\n";
        if ($code === 0) { $totalPass++; echo "  ✅ E2E 通过\n"; }
        else { $totalFail++; echo "  ❌ E2E 失败\n"; }
    } else {
        echo "  ⚠️ run-e2e.sh 缺失, 跳过\n";
    }
}

// ─── 汇总 ───
echo "\n════════════════════════════════════\n";
echo "阻断级通过: {$totalPass}  失败: {$totalFail}" . ($advisoryWarn > 0 ? "  建议级警告: {$advisoryWarn}" : '') . "\n";
if ($totalFail > 0) {
    echo "\n❌ 门禁未通过. 失败项:\n";
    foreach ($failed as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "✅ 统一验证门全绿" . ($advisoryWarn > 0 ? " (有 {$advisoryWarn} 项建议级历史债, 不阻断)" : '') . "\n";
exit(0);

/**
 * 收集待 lint 的 PHP 文件: staged 模式取 git 暂存区; 否则扫业务目录(排除 vendor/node_modules)。
 * @return list<string>
 */
function collectPhpFiles(string $root, bool $staged): array
{
    if ($staged) {
        [, $lines] = runCmd('git -C ' . escapeshellarg($root) . ' diff --cached --name-only --diff-filter=ACM');
        $out = [];
        foreach ($lines as $f) {
            if (str_ends_with(trim($f), '.php')) {
                $out[] = $root . '/' . trim($f);
            }
        }
        return $out;
    }
    $out = [];
    foreach (['src', 'public', 'scripts', 'views', 'checks'] as $dir) {
        $base = $root . '/' . $dir;
        if (!is_dir($base)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
    }
    return $out;
}
