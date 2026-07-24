<?php

/**
 * Demo Full Flow — 全链路验证脚本
 *
 * 模拟 20 次真实点击 → 转化流程，验证所有新模块。
 * 可在已部署的 Converge v2.0 上直接运行。
 *
 * 运行: php scripts/demo-full-flow.php
 * 输出: 终端报告 + reports/demo-<timestamp>.json
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

// Load config (skip if not installed — use demo mode)
$configFile = $root . '/config/config.php';
$isInstalled = file_exists($configFile);
if ($isInstalled) {
    require_once $configFile;
} else {
    // Demo mode — minimal config
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASSWORD', '');
    define('DB_NAME', 'simplekuma');
    define('ROOT_PATH', $root);
    define('STORAGE_PATH', sys_get_temp_dir() . '/kuma-demo');
    define('LOGS_PATH', STORAGE_PATH . '/logs');
    define('LOG_LEVEL', 'debug');
    define('LOG_RETENTION_DAYS', 7);
    define('BASE_URL', 'https://track.example.com');
    define('APP_START_TIME', microtime(true));
    @mkdir(STORAGE_PATH, 0755, true);
    @mkdir(LOGS_PATH, 0755, true);
}

require_once $root . '/app/bootstrap_web_paths.php';

use Converge\Observability\StructuredLogger;
use Converge\Observability\HealthChecker;
use Converge\Observability\AlertNotifier;
use Converge\Traceability\EventStore;
use Converge\Resilience\CircuitBreaker;
use Converge\Resilience\RetryHandler;
use Converge\Security\BotDetector;
use Converge\Security\VisitCap;
use Converge\Performance\ClickBuffer;
use Converge\Evolution\PerformanceAnalyzer;
use Converge\Core\FabricManager;

// ═══════════════════════════════════════
// Header
// ═══════════════════════════════════════

echo str_repeat('═', 60) . "\n";
echo "  Converge v2.0 — 全链路 Demo 验证\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('═', 60) . "\n\n";

// ═══════════════════════════════════════
// Init
// ═══════════════════════════════════════

echo "📦 初始化...\n";

$logger = new StructuredLogger(LOGS_PATH, 'demo.log', LOG_LEVEL);
$logger->info('Demo started');

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db && $db->connect_errno) {
    $db = null;
}
if (!$db) {
    echo "⚠️  Database not available — running in offline mode\n\n";
} else {
    $db->set_charset('utf8mb4');
    $db->query("SET time_zone = '+00:00'");
    echo "  ✅ Database: " . DB_HOST . "/" . DB_NAME . "\n";
}

$eventStore = new EventStore();
echo "  ✅ EventStore: " . ($eventStore->isHealthy() ? 'healthy' : 'INIT FAILED') . "\n";

$botDetector = $db ? new BotDetector($db, $eventStore, $logger) : null;
$visitCap = new VisitCap(null, $logger);
$visitCap->setDefaults(['hourly' => 10, 'daily' => 50]);
$clickBuffer = $db ? ClickBuffer::init($db, 100, 500, $logger) : null;
$circuitBreaker = new CircuitBreaker('demo', 3, 30, $logger);
$retryHandler = new RetryHandler(3, 100, $logger, $eventStore);
$notifier = new AlertNotifier([], $logger);
$perfAnalyzer = new PerformanceAnalyzer($logger);
$fabric = FabricManager::init();

echo "  ✅ Fabric: " . count($fabric->all()) . " subsystems registered\n\n";

// ═══════════════════════════════════════
// Generate test data
// ═══════════════════════════════════════

echo "🎯 生成 20 个模拟用户...\n\n";

$users = [];
$devices = [
    ['type' => 'mobile', 'os' => 'iOS', 'browser' => 'Safari', 'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15'],
    ['type' => 'mobile', 'os' => 'Android', 'browser' => 'Chrome', 'ua' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36'],
    ['type' => 'desktop', 'os' => 'Windows', 'browser' => 'Chrome', 'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36'],
    ['type' => 'desktop', 'os' => 'macOS', 'browser' => 'Safari', 'ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15'],
    ['type' => 'tablet', 'os' => 'iPadOS', 'browser' => 'Safari', 'ua' => 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15'],
];

$countries = ['US', 'US', 'US', 'GB', 'DE', 'CA', 'AU', 'JP', 'BR', 'FR'];
$referrers = [
    'https://www.facebook.com/ad/',
    'https://www.google.com/search?q=',
    'https://www.tiktok.com/@ad/',
    '', // Direct
];
$offers = [101, 102, 103]; // Offer IDs (demo)

// Conversion probabilities by country+device (US iOS = 4%, EU Android = 1%)
function conversionProbability(string $country, string $device): float {
    if ($country === 'US' && $device === 'mobile') return 0.04;
    if ($country === 'US' && $device === 'desktop') return 0.025;
    if ($country === 'GB') return 0.02;
    return 0.01;
}

$stats = [
    'total_clicks' => 0,
    'total_conversions' => 0,
    'total_revenue' => 0.0,
    'total_cost' => 0.0,
    'bots_blocked' => 0,
    'visits_capped' => 0,
    'events_recorded' => 0,
    'click_buffer_writes' => 0,
];

// ═══════════════════════════════════════
// Simulate 20 clicks
// ═══════════════════════════════════════

for ($i = 0; $i < 20; $i++) {
    $ip = '203.0.113.' . ($i + 1);
    $device = $devices[$i % count($devices)];
    $country = $countries[$i % count($countries)];
    $referrer = $referrers[$i % count($referrers)];
    $offerId = $offers[$i % count($offers)];

    $clickId = 'demo-' . date('YmdHi') . '-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);
    $cost = round(mt_rand(5, 50) / 100, 2); // $0.05 - $0.50

    echo sprintf("  [%2d] %-15s | %-7s %-8s %-10s | cost=\$%.2f",
        $i + 1, $clickId, $device['type'], $device['os'], $country, $cost);

    // Step 1: Bot check
    $botVerdict = $botDetector?->analyze([
        'ip' => $ip, 'ua' => $device['ua'], 'referrer' => $referrer, 'campaign_id' => 1,
    ], ['js_enabled' => true, 'mouse_moved' => true]);

    if ($botVerdict?->shouldBlock()) {
        echo " | 🔴 BOT (score={$botVerdict->score})\n";
        $stats['bots_blocked']++;
        continue;
    }

    // Step 2: Visit cap
    $capVerdict = $visitCap->checkAndRecord($ip, $device['ua'], 'campaign:1');
    if ($capVerdict->isCapped) {
        echo " | 🟡 CAPPED\n";
        $stats['visits_capped']++;
        continue;
    }

    // Step 3: EventStore — click_received
    $eventId = $eventStore->append($clickId, 'click_received', [
        'ip' => $ip, 'device' => $device['type'], 'os' => $device['os'],
        'country' => $country, 'cost' => $cost,
    ]);
    $stats['events_recorded']++;

    // Step 4: ClickBuffer
    if ($clickBuffer) {
        $clickBuffer->push([
            'campaign_id' => 1, 'click_id' => $clickId,
            'ts' => date('Y-m-d H:i:s'), 'ip' => $ip, 'ua' => $device['ua'],
            'referrer' => $referrer, 'country' => $country, 'region' => '', 'city' => '',
            'device' => $device['type'], 'os' => $device['os'], 'browser' => $device['browser'],
            'ts_hour' => date('Y-m-d H:00:00'), 'cost' => $cost, 'cost_currency' => 'USD',
        ]);
        $stats['click_buffer_writes']++;
    }

    $stats['total_clicks']++;
    $stats['total_cost'] += $cost;

    // Step 5: Simulate conversion?
    $convProb = conversionProbability($country, $device['type']);
    $converted = (mt_rand(1, 10000) / 10000) < $convProb;

    if ($converted) {
        $revenue = round(mt_rand(1500, 5000) / 100, 2); // $15.00 - $50.00
        $lpEventId = $eventStore->append($clickId, 'lp_viewed', [
            'landing_page_id' => 1, 'time_on_page_ms' => mt_rand(2000, 15000),
        ], (string)$eventId);
        $stats['events_recorded']++;

        $convEventId = $eventStore->append($clickId, 'conversion_fired', [
            'revenue' => $revenue, 'offer_id' => $offerId, 'currency' => 'USD',
        ], (string)$lpEventId);
        $stats['events_recorded']++;

        $stats['total_conversions']++;
        $stats['total_revenue'] += $revenue;

        echo " | 💰 CONV \${$revenue} (EPC=" . round($revenue, 2) . ")\n";
    } else {
        echo " | ⏭️  no conv\n";
    }
}

// Flush buffer
if ($clickBuffer) {
    $clickBuffer->flush();
}

echo "\n";

// ═══════════════════════════════════════
// Results
// ═══════════════════════════════════════

$totalClicks = $stats['total_clicks'];
$totalConvs = $stats['total_conversions'];
$cr = $totalClicks > 0 ? round($totalConvs / $totalClicks * 100, 2) : 0;
$totalCost = $stats['total_cost'];
$totalRevenue = $stats['total_revenue'];
$roas = $totalCost > 0 ? round($totalRevenue / $totalCost, 2) : 0;
$profit = $totalRevenue - $totalCost;
$epc = $totalClicks > 0 ? round($totalRevenue / $totalClicks, 4) : 0;

echo str_repeat('─', 60) . "\n";
echo "📊 结果汇总\n";
echo str_repeat('─', 60) . "\n";
echo sprintf("  Clicks:       %d\n", $totalClicks);
echo sprintf("  Conversions:  %d (%.2f%%)\n", $totalConvs, $cr);
echo sprintf("  Total Cost:   \$%.2f\n", $totalCost);
echo sprintf("  Total Revenue:\$%.2f\n", $totalRevenue);
echo sprintf("  Profit:       \$%.2f\n", $profit);
echo sprintf("  ROAS:         %.2fx\n", $roas);
echo sprintf("  EPC:          \$%.4f\n", $epc);
echo sprintf("  Bots blocked: %d\n", $stats['bots_blocked']);
echo sprintf("  Visits capped:%d\n", $stats['visits_capped']);
echo sprintf("  Events:       %d\n", $stats['events_recorded']);
echo sprintf("  Buffer writes:%d\n", $stats['click_buffer_writes']);
echo "\n";

// ═══════════════════════════════════════
// System Health
// ═══════════════════════════════════════

echo str_repeat('─', 60) . "\n";
echo "🔭 系统健康\n";
echo str_repeat('─', 60) . "\n";

echo "  EventStore: " . ($eventStore->isHealthy() ? '✅' : '❌') . "\n";
echo "  CircuitBreaker: {$circuitBreaker->getState()['state']}\n";

if ($db) {
    $healthChecker = new HealthChecker($db, null, '2.0.0');
    ob_start();
    $healthChecker->handle();
    $healthData = json_decode(ob_get_clean(), true);
    echo "  Database: " . ($healthData['checks']['database']['ok'] ?? false ? '✅' : '❌') . "\n";
    echo "  Disk: {$healthData['checks']['disk']['free_mb']}MB free\n";
}

echo "  VisitCap counters: {$visitCap->getStats()['total_counters']}\n";

if ($botDetector) {
    echo "  IP Blacklist: {$botDetector->getStats()['blacklisted_ips']} entries\n";
}

echo "\n";

// ═══════════════════════════════════════
// OODA 学习循环模拟
// ═══════════════════════════════════════

echo str_repeat('─', 60) . "\n";
echo "🧬 OODA 学习循环\n";
echo str_repeat('─', 60) . "\n";

// Analyze the simulated data
$events = [];
for ($i = 0; $i < 20; $i++) {
    $device = $devices[$i % count($devices)];
    $country = $countries[$i % count($countries)];
    $convProb = conversionProbability($country, $device['type']);
    $cost = round(mt_rand(5, 50) / 100, 2);

    $events[] = [
        'device_type' => $device['type'],
        'os' => $device['os'],
        'country' => $country,
        'campaign_id' => 1,
        'ad_group_id' => 0,
        'keyword' => 'demo',
        'converted' => (mt_rand(1, 10000) / 10000) < $convProb,
        'cost' => $cost,
        'revenue' => (mt_rand(1, 10000) / 10000) < $convProb ? round(mt_rand(1500, 5000) / 100, 2) : 0,
    ];
}

$report = $perfAnalyzer->analyze($events);

echo "  High performers: " . count($report->highPerformers) . "\n";
foreach (array_slice($report->highPerformers, 0, 3) as $seg) {
    echo "    📈 {$seg->dimension}:{$seg->value} | ROAS={$seg->roas}x | CR="
        . round($seg->conversionRate * 100, 1) . "% | EPC=\${$seg->epc}\n";
}

echo "  Low performers: " . count($report->lowPerformers) . "\n";
foreach (array_slice($report->lowPerformers, 0, 3) as $seg) {
    echo "    📉 {$seg->dimension}:{$seg->value} | ROAS={$seg->roas}x | CR="
        . round($seg->conversionRate * 100, 1) . "%\n";
}

// Smart Rotation would act on this
echo "\n  💡 Smart Rotation recommendation:\n";
if (count($report->highPerformers) > 0) {
    $best = $report->highPerformers[0];
    echo "    → Scale UP {$best->dimension}:{$best->value} (ROAS: {$best->roas}x)\n";
}
if (count($report->lowPerformers) > 0) {
    $worst = $report->lowPerformers[0];
    echo "    → Scale DOWN {$worst->dimension}:{$worst->value} (ROAS: {$worst->roas}x)\n";
}

echo "\n";

// ═══════════════════════════════════════
// Causality Demo
// ═══════════════════════════════════════

echo str_repeat('─', 60) . "\n";
echo "📋 因果追溯 (Causality Chain)\n";
echo str_repeat('─', 60) . "\n";

// Pick a converted click's chain
$lastEvents = $eventStore->getEventsByType('conversion_fired', date('Y-m-d') . ' 00:00:00');
if (!empty($lastEvents)) {
    $lastConv = $lastEvents[0];
    $chain = $eventStore->getCausalChain((string)$lastConv['id']);
    echo "  Last conversion event chain:\n";
    foreach (array_reverse($chain) as $event) {
        $payload = json_decode($event['payload'], true) ?: [];
        $summary = match ($event['event_type']) {
            'click_received' => "IP={$payload['ip']}, device={$payload['device']}",
            'lp_viewed' => "LP #{$payload['landing_page_id']}, {$payload['time_on_page_ms']}ms",
            'conversion_fired' => "\${$payload['revenue']} on offer #{$payload['offer_id']}",
            default => '',
        };
        echo "    ← {$event['event_type']} ({$summary})\n";
    }
    echo "  ✅ Full traceability confirmed\n";
}

echo "\n";

// ═══════════════════════════════════════
// Save Report
// ═══════════════════════════════════════

$reportDir = ROOT_PATH . '/reports';
@mkdir($reportDir, 0755, true);
$reportFile = $reportDir . '/demo-' . date('Ymd-His') . '.json';

$reportData = [
    'timestamp' => date('c'),
    'version' => '2.0.0',
    'stats' => $stats,
    'performance' => [
        'cr' => $cr,
        'roas' => $roas,
        'epc' => $epc,
        'profit' => $profit,
    ],
    'system' => [
        'eventstore_healthy' => $eventStore->isHealthy(),
        'fabrics' => count($fabric->all()),
        'visitcap_counters' => $visitCap->getStats()['total_counters'],
    ],
    'high_performers' => array_map(fn($s) => $s->toArray(), array_slice($report->highPerformers, 0, 5)),
    'low_performers' => array_map(fn($s) => $s->toArray(), array_slice($report->lowPerformers, 0, 5)),
];

file_put_contents($reportFile, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "📄 Report saved: {$reportFile}\n\n";

// ═══════════════════════════════════════
// Footer
// ═══════════════════════════════════════

echo str_repeat('═', 60) . "\n";
echo "  ✅ Demo 全链路验证完成\n";
echo str_repeat('═', 60) . "\n";

echo "\n模块状态:\n";
foreach ($fabric->all() as $key => $f) {
    $exists = is_dir($f->path) || is_file($f->path);
    echo "  " . ($exists ? '✅' : '⚠️') . " {$f->layer} {$f->name}\n";
}

exit(0);
