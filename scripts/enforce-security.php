#!/usr/bin/env php
<?php
/**
 * enforce-security.php — 安全扫描门禁 (OWASP Top 5)
 *
 * 检测:
 *   1. SQL 注入 — 字符串拼接 SQL
 *   2. 硬编码密钥 — API_KEY / SECRET / password 字面量
 *   3. 目录遍历 — 未验证的文件路径
 *   4. 日志泄露 — 记录密码/token
 *   5. eval/exec — 危险函数
 *
 * 用法: php scripts/enforce-security.php [--staged] [--json]
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
        if ($f && str_ends_with($f, '.php') && file_exists("$ROOT/$f")) {
            $files[] = "$ROOT/$f";
        }
    }
} else {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ROOT)
    );
    foreach ($iter as $f) {
        if ($f->getExtension() !== 'php') continue;
        $path = $f->getPathname();
        if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) continue;
        $files[] = $path;
    }
}

if (empty($files)) {
    echo $JSON ? '{"status":"clean"}' : "  ✅ No PHP files\n";
    exit(0);
}

// ═══ Scan ═══
$counts = ['sql_injection' => 0, 'hardcoded_secret' => 0, 'path_traversal' => 0,
           'log_leak' => 0, 'dangerous_fn' => 0];

foreach ($files as $file) {
    $scanned++;
    $content = file_get_contents($file);
    $rel = str_replace($ROOT . '/', '', $file);

    // 1. SQL injection: string concatenation with SQL keywords
    if (preg_match('/["\']\s*\.\s*\$[a-zA-Z_].*(SELECT|INSERT|UPDATE|DELETE|WHERE)\b/i', $content)) {
        $violations[] = ['file' => $rel, 'type' => 'sql_injection',
            'msg' => 'String-concatenated SQL — use prepared statements'];
        $counts['sql_injection']++;
    }

    // 2. Hardcoded secrets
    if (preg_match('/(\$|const\s+)(API_KEY|SECRET|PASSWORD|TOKEN)\s*=\s*[\'"][^\'"]{8,}[\'"]/i', $content, $m)) {
        if (!str_contains($content, 'getenv') && !str_contains($content, '$_ENV')) {
            $violations[] = ['file' => $rel, 'type' => 'hardcoded_secret',
                'msg' => "Hardcoded secret detected — use environment variables"];
            $counts['hardcoded_secret']++;
        }
    }

    // 3. Path traversal: unvalidated file paths with user input
    if (preg_match('/(fopen|file_get_contents|include|require).*\$_(GET|POST|REQUEST)/', $content)) {
        $violations[] = ['file' => $rel, 'type' => 'path_traversal',
            'msg' => 'User input in file path — validate against whitelist'];
        $counts['path_traversal']++;
    }

    // 4. Log leaks: logging passwords/tokens
    if (preg_match('/(error_log|Logger::|->log|console\.log).*(password|token|secret|key)/i', $content)) {
        $violations[] = ['file' => $rel, 'type' => 'log_leak',
            'msg' => 'Sensitive data in log statement — redact before logging'];
        $counts['log_leak']++;
    }

    // 5. Dangerous functions
    if (preg_match('/\b(eval|exec|system|passthru|shell_exec)\s*\(/', $content)) {
        $violations[] = ['file' => $rel, 'type' => 'dangerous_fn',
            'msg' => 'Dangerous function — eval/exec/system'];
        $counts['dangerous_fn']++;
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
        'details' => array_slice($violations, 0, 30),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($total > 0 ? 1 : 0);
}

echo "═══ 安全门禁 — $scanned files ═══\n";

if ($total === 0) {
    echo "  ✅ No security issues\n";
    exit(0);
}

foreach ($violations as $v) {
    echo "  ❌ {$v['file']}: {$v['msg']}\n";
}
echo "\n  ❌ {$total} security violations — BLOCKED\n";
exit(1);
