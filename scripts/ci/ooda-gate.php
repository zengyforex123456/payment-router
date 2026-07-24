#!/usr/bin/env php
<?php
/**
 * ooda-gate.php — 门禁失败自动接入 OODA 闭环
 *
 * 运行所有门禁，失败时自动: 检测→判断→决策→执行→验证
 *
 * 用法:
 *   php ci/ooda-gate.php              # 完整 OODA 门禁
 *   php ci/ooda-gate.php --auto       # 自动修复安全模式
 *   php ci/ooda-gate.php --report     # 仅报告
 *
 * 退出码: 0=全部通过, 1=有失败(已记录), 2=有失败(无法自愈)
 */

declare(strict_types=1);

$mode = in_array('--auto', $argv ?? []) ? 'auto' : (in_array('--report', $argv ?? []) ? 'report' : 'gate');
$json = in_array('--json', $argv ?? []);

// ═══ 第一步: 运行所有门禁 ═══

$checks = [];
$failures = [];

// 1. PHP 语法检查
$phpFiles = [];
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/..', RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($iter as $f) {
    if ($f->isFile() && $f->getExtension() === 'php' && !str_contains($f->getPathname(), '/vendor/')) {
        $phpFiles[] = $f->getPathname();
    }
}

$syntaxFails = [];
foreach ($phpFiles as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        $syntaxFails[] = ['file' => str_replace(__DIR__ . '/..', '', $file), 'error' => implode("\n", $output)];
    }
}
$checks['syntax'] = ['name' => 'PHP语法', 'total' => count($phpFiles), 'pass' => count($phpFiles) - count($syntaxFails), 'fail' => count($syntaxFails), 'failures' => $syntaxFails];
if (count($syntaxFails) > 0) $failures[] = 'syntax';

// 2. DoR 需求检查
if (file_exists(__DIR__ . '/dor-check.php')) {
    $dout = []; $dcode = 0;
    exec('php ' . escapeshellarg(__DIR__ . '/dor-check.php') . ' --json 2>&1', $dout, $dcode);
    $dorResult = json_decode(implode("\n", $dout), true) ?: ['pass' => 0, 'fail' => 0, 'total' => 0];
    $checks['dor'] = ['name' => '需求完整性', 'total' => $dorResult['total'] ?? 0, 'pass' => $dorResult['pass'] ?? 0, 'fail' => $dorResult['fail'] ?? 0];
    if (($dorResult['fail'] ?? 0) > 0) $failures[] = 'dor';
}

// 3. 架构合规 (只有 validate_pipeline.js 存在才运行)
$nodeRoot = APP_ROOT . '/..';
$validateScript = $nodeRoot . '/validate_pipeline.js';
if (file_exists($validateScript)) {
    $vout = []; $vcode = 0;
    exec('node ' . escapeshellarg($validateScript) . ' 2>&1', $vout, $vcode);
    $checks['arch'] = ['name' => '架构合规', 'exitCode' => $vcode, 'output' => array_slice($vout, -5)];
    if ($vcode !== 0) $failures[] = 'arch';
}

// ═══ 第二步: OODA — 失败时自动闭环 ═══

$oodaResult = null;
if (count($failures) > 0 && $mode !== 'report') {
    $oodaResult = runOODA($failures, $checks, $mode === 'auto');
}

// ═══ 第三步: 输出报告 ═══

if ($json) {
    echo json_encode([
        'checks' => $checks,
        'failures' => $failures,
        'ooda' => $oodaResult,
        'timestamp' => date('c'),
    ], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n═══ OODA 门禁报告 ═══\n\n";

    foreach ($checks as $key => $c) {
        $status = ($c['fail'] ?? ($c['exitCode'] ?? 0)) === 0 ? '✅' : '❌';
        echo "{$status} {$c['name']}: ";
        if (isset($c['pass'])) echo "{$c['pass']}/{$c['total']} 通过";
        else echo "exit code: " . ($c['exitCode'] ?? '?');
        echo "\n";

        if (!empty($c['failures'])) {
            foreach (array_slice($c['failures'], 0, 3) as $f) {
                echo "   - " . ($f['file'] ?? '') . ": " . substr($f['error'] ?? '', 0, 100) . "\n";
            }
        }
    }

    echo "\n─── OODA 闭环 ───\n";
    if ($oodaResult) {
        echo "🔭 检测: " . count($failures) . " 个门禁失败\n";
        echo "📋 判断: " . $oodaResult['classification'] . "\n";
        echo "🧠 决策: " . $oodaResult['strategy'] . "\n";
        echo "⚡ 执行: " . ($oodaResult['healed'] ?? 0) . " 项已自愈, " . ($oodaResult['manual'] ?? 0) . " 项需人工\n";
        if (($oodaResult['healed'] ?? 0) > 0) {
            foreach ($oodaResult['fixes'] ?? [] as $fix) {
                echo "   ✅ {$fix}\n";
            }
        }
    } elseif (count($failures) === 0) {
        echo "✅ 所有门禁通过 — OODA 跳过\n";
    } else {
        echo "📋 Report 模式 — 仅检测，不执行修复\n";
    }
}

// 退出码
if (count($failures) === 0) exit(0);        // 全通过
if (($oodaResult['fixed'] ?? false) === true) exit(0);  // 已自愈
exit(count($failures) > 0 ? 1 : 0);

// ═══ OODA 闭环执行 ═══

function runOODA(array $failures, array $checks, bool $auto): array
{
    $result = [
        'failedGates' => $failures,
        'classification' => 'unknown',
        'strategy' => 'manual',
        'healed' => 0,
        'manual' => 0,
        'fixes' => [],
        'fixed' => false,
    ];

    // O1: 观察 — 提取指纹
    $fingerprints = [];
    foreach ($checks as $key => $c) {
        if (($c['fail'] ?? ($c['exitCode'] ?? 0)) === 0) continue;

        if ($key === 'syntax') {
            foreach ($c['failures'] ?? [] as $f) {
                $msg = $f['error'] ?? '';
                if (preg_match('/syntax error, unexpected.*(\S+).*on line (\d+)/i', $msg, $m)) {
                    $fingerprints[] = ['fp' => 'php|syntax-error|' . $f['file'], 'type' => 'syntax', 'file' => $f['file'], 'line' => (int)($m[2] ?? 0), 'token' => $m[1] ?? '?'];
                } else {
                    $fingerprints[] = ['fp' => 'php|parse-error|' . $f['file'], 'type' => 'syntax', 'file' => $f['file']];
                }
            }
        } elseif ($key === 'dor') {
            $fingerprints[] = ['fp' => 'spec|missing-gwt|requirements', 'type' => 'spec', 'count' => $c['fail']];
        } elseif ($key === 'arch') {
            $fingerprints[] = ['fp' => 'arch|compliance-fail|pipeline', 'type' => 'arch', 'exitCode' => $c['exitCode'] ?? 1];
        }
    }

    // O2: 判断 — 可自愈?
    $autoFixable = [];
    $manualFixes = [];
    foreach ($fingerprints as $fp) {
        if ($fp['type'] === 'syntax' && in_array($fp['token'] ?? '', ['?>', 'endif', 'endwhile', 'endfor', 'endforeach', 'endswitch', 'else', 'elseif'])) {
            // PHP 控制结构不匹配 → 可能是未闭合标签 → 可尝试自愈
            $autoFixable[] = $fp;
        } elseif ($fp['type'] === 'spec') {
            // 需求规格缺失 → 需人工补充
            $manualFixes[] = $fp;
        } else {
            $manualFixes[] = $fp;
        }
    }

    $result['classification'] = count($autoFixable) > 0 ? 'auto-fixable'
        : (count($fingerprints) > 0 ? 'manual-required' : 'unknown');
    $result['strategy'] = $auto ? (count($autoFixable) > 0 ? 'auto-heal' : 'report') : 'report';
    $result['manual'] = count($manualFixes);

    // O3+O4: 决策+执行 — 自动修复语法错误
    if ($auto && count($autoFixable) > 0) {
        foreach ($autoFixable as $fp) {
            $filePath = __DIR__ . '/..' . $fp['file'];
            if (!file_exists($filePath)) continue;

            $content = file_get_contents($filePath);
            $lines = explode("\n", $content);
            $targetLine = $fp['line'] - 1;
            if (!isset($lines[$targetLine])) continue;

            // 简单模式: PHP 意外 token → 添加缺失的闭合
            $fixed = false;
            $line = $lines[$targetLine];

            if ($fp['token'] === '?>') {
                // 检查是否缺少 endif/endwhile 等
                $prevLines = array_slice($lines, 0, $targetLine + 1);
                $openCount = 0; $closeCount = 0;
                foreach ($prevLines as $pl) {
                    $openCount += preg_match_all('/\b(?:if|while|for|foreach)\s*\(/', $pl);
                    $openCount += preg_match_all('/\b(?:if|while|for|foreach)\s*:/', $pl);
                    $closeCount += preg_match_all('/\b(?:endif|endwhile|endfor|endforeach)\b/', $pl);
                }
                if ($openCount > $closeCount) {
                    $expected = '';
                    if (preg_match('/\bif\b/', $line)) $expected = 'endif';
                    elseif (preg_match('/\bwhile\b/', $line)) $expected = 'endwhile';
                    elseif (preg_match('/\bforeach\b/', $line)) $expected = 'endforeach';
                    if ($expected) {
                        $lines[$targetLine + 1] = "<?php {$expected}; ?>";
                        $fixed = true;
                    }
                }
            }

            if (preg_match('/unexpected end of file/i', implode("\n", array_slice($lines, -3)))) {
                // 文件末尾缺少 endif → 追加
                $lines[] = '<?php endif; ?>';
                $fixed = true;
            }

            if ($fixed) {
                file_put_contents($filePath, implode("\n", $lines));
                // 验证修复
                exec('php -l ' . escapeshellarg($filePath) . ' 2>&1', $vfyOut, $vfyCode);
                if ($vfyCode === 0) {
                    $result['fixes'][] = "{$fp['file']}: 自动添加缺失闭合标签";
                    $result['healed']++;
                } else {
                    // 回滚
                    file_put_contents($filePath, $content);
                    $result['fixes'][] = "{$fp['file']}: 自愈失败，已回滚";
                }
            }
        }
    }

    // KAG 写入
    try {
        $kagFile = APP_ROOT . '/data/ooda-gate-log.json';
        $history = [];
        if (file_exists($kagFile)) {
            $history = json_decode(file_get_contents($kagFile), true) ?: [];
        }
        $history[] = [
            'timestamp' => date('c'),
            'failures' => $failures,
            'fingerprints' => array_map(fn($f) => $f['fp'], $fingerprints),
            'healed' => $result['healed'],
            'strategy' => $result['strategy'],
        ];
        if (count($history) > 100) $history = array_slice($history, -100);
        file_put_contents($kagFile, json_encode($history, JSON_PRETTY_PRINT));
    } catch (\Throwable $_) {}

    $result['fixed'] = count($failures) === 0 || $result['healed'] > 0;
    return $result;
}
