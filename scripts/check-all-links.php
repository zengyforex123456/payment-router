<?php
declare(strict_types=1);
/**
 * 全量链接可触达检测 — page-registry.json + public/*.php + API 端点
 * Usage: php scripts/check-all-links.php [--base=http://localhost:8080]
 */
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$results = ['ok' => [], 'redirect' => [], 'missing' => [], 'error' => []];

function curl($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'error' => $err];
}

function check($url, $label, &$results) {
    $r = curl($url);
    $code = $r['code'];
    if ($r['error']) { $results['error'][] = [$url, $label, $r['error']]; return; }
    if ($code === 200) $results['ok'][] = [$url, $label, $code];
    elseif (in_array($code, [301, 302, 303, 307, 308])) $results['redirect'][] = [$url, $label, $code];
    elseif ($code === 404) $results['missing'][] = [$url, $label, $code];
    else $results['error'][] = [$url, $label, "HTTP $code"];
}

// 1. page-registry.json 全部链接
$registry = json_decode(file_get_contents(__DIR__ . '/../.claude/reference/page-registry.json'), true);
$done = [];
foreach ($registry['menus'] as $panelKey => $panel) {
    foreach ($panel['items'] as $item) {
        $url = $base . $item['url'];
        $id = $item['id'];
        if (isset($done[$url])) continue;
        $done[$url] = true;
        check($url, "sidebar:{$id}", $results);
    }
}

// 2. public/*.php 入口文件
$trackerEndpoints = ['click.php','go.php','km.php','kumahop.php','cloak.php','pixel.php','postback.php','funnel-event.php','landing-track.php','fire-postback-for-conversion.php'];
foreach (glob(__DIR__ . '/../public/*.php') as $file) {
    $name = basename($file);
    if (str_starts_with($name, '_') || str_starts_with($name, 'api-')) continue;
    if (in_array($name, $trackerEndpoints)) continue; // require query/POST params
    $url = $base . '/' . $name;
    if (isset($done[$url])) continue;
    $done[$url] = true;
    check($url, "public:{$name}", $results);
}

// 3. API 端点
$apis = [
    '/api/live-stats.php',
    '/api/attention/roi-alerts.php',
];
foreach ($apis as $api) {
    $url = $base . $api;
    check($url, "api:{$api}", $results);
}

// 4. 特殊页面
$specials = [
    '/login-v2.php', '/register.php', '/landing.php', '/landing2.php',
    '/2fa-setup.php', '/2fa-verify.php', '/reset-password.php', '/forgot-password.php',
    '/index.php', '/index.php?page=campaigns',
];
foreach ($specials as $s) {
    $url = $base . $s;
    if (isset($done[$url])) continue;
    $done[$url] = true;
    check($url, "special", $results);
}

// ═══ Output ═══
$ok = count($results['ok']);
$redir = count($results['redirect']);
$missing = count($results['missing']);
$errs = count($results['error']);
$total = $ok + $redir + $missing + $errs;

echo "═══ 全量链接可触达检测 ═══\n";
echo "  Total: $total\n";
echo "  ✅ 200: $ok\n";
echo "  ↪ Redirect (OK): $redir\n";

if ($missing) {
    echo "  ❌ 404 MISSING: $missing\n";
    foreach ($results['missing'] as [$url, $label, $code]) {
        echo "     $label → $url\n";
    }
}

if ($errs) {
    echo "  ❌ ERROR: $errs\n";
    foreach ($results['error'] as [$url, $label, $msg]) {
        echo "     $label → $url  ($msg)\n";
    }
}

if (!$missing && !$errs) echo "  🎉 All links reachable!\n";

echo "════════════════════════════════\n";
exit($missing + $errs ? 1 : 0);
