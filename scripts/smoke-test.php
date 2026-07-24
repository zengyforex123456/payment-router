<?php
/**
 * smoke-test.php — 全站冒烟测试
 *
 * 扫描 public/*.php → curl 每个页面 → 检测致命错误/500/404
 *
 * 用法:
 *   php scripts/smoke-test.php              # 测试所有页面
 *   php scripts/smoke-test.php landing      # 只测 landing
 *   php scripts/smoke-test.php --json       # JSON 输出
 */
$baseUrl = getenv('APP_URL') ?: 'http://localhost:8080';
$filter = $argv[1] ?? null;
$json = in_array('--json', $argv);

// 跳过 API 端点和特殊文件
$skip = [
    'api-', 'api/', 'km.php', 'postback.php', 'click.php', 'pixel.php',
    'track.php', 'logout.php', 'health.php', 'fire-', 'lp/',
    '_test', 'test-', 'p.php',
];

$publicDir = __DIR__ . '/../public';
$files = glob($publicDir . '/*.php');
$results = ['pass' => [], 'fail' => [], 'skip' => []];

if (!$json) echo "═══ Converge Smoke Test ═══\n";
if (!$json) echo "Base: {$baseUrl}\n\n";

foreach ($files as $file) {
    $name = basename($file);

    // Filter
    if ($filter && !str_contains($name, $filter)) continue;

    // Skip
    $shouldSkip = false;
    foreach ($skip as $pattern) {
        if (str_starts_with($name, $pattern)) { $shouldSkip = true; break; }
    }
    if ($shouldSkip) { $results['skip'][] = $name; continue; }

    // Test
    $url = $baseUrl . '/' . $name;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_NOBODY => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $status = '✅';
    $reason = '';

    if ($err) {
        $status = '❌';
        $reason = "curl error: {$err}";
    } elseif ($code >= 500) {
        $status = '❌';
        $reason = "HTTP {$code}";
    } elseif ($code === 404) {
        $status = '⚠️';
        $reason = "HTTP 404";
    } elseif ($code === 302) {
        $status = '↪️';
        $reason = "302 redirect";
    } elseif (preg_match('/(Fatal error|Parse error|Uncaught|Stack trace)/', $body ?: '')) {
        $status = '❌';
        if (preg_match('/<b>([^<]+)<\/b>/', $body, $m)) {
            $reason = strip_tags($m[1]);
        } else {
            $reason = 'Fatal error';
        }
    } elseif ($code !== 200) {
        $status = '⚠️';
        $reason = "HTTP {$code}";
    }

    $entry = ['page' => $name, 'code' => $code, 'reason' => $reason];
    if ($status === '✅') {
        $results['pass'][] = $entry;
    } else {
        $results['fail'][] = $entry;
    }

    if (!$json) printf("  %s %-35s %s\n", $status, $name, $reason ? "({$reason})" : '');
}

if ($json) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(count($results['fail']) > 0 ? 1 : 0);
}

$pass = count($results['pass']);
$fail = count($results['fail']);
$skip = count($results['skip']);
$total = $pass + $fail;
echo "\n──────────────────────────\n";
echo "Pass: {$pass} | Fail: {$fail} | Skip: {$skip}\n";

if ($fail > 0) {
    echo "\n❌ Failed pages:\n";
    foreach ($results['fail'] as $f) {
        echo "  {$f['page']} — {$f['reason']}\n";
    }
}

exit($fail > 0 ? 1 : 0);
