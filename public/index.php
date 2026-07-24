<?php
/**
 * index.php — Converge 统一入口 (Latte 路由)
 *
 * 路由: ?page=dashboard | campaigns | settings | analytics
 * 认证用户 → index.latte (App Shell + 内容片段)
 * 未认证 → landing.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

\Converge\Security\Auth\SessionManager::init();

// 未登录 → Landing
if (empty($_SESSION['user_id'])) {
    header('Location: /landing.php', true, 302);
    exit;
}

use Converge\Security\Auth;
use Converge\I18n\Locale;
use Converge\UI\Engine\LatteEngine;

$db = db();
$auth = new Auth($db->raw());
$currentUser = $auth->getCurrentUser();

Locale::init();
$lang = Locale::lang();
$zh = $lang === 'zh';

// ═══ 路由: ?page=xxx ───
$page = $_GET['page'] ?? '';
// Redirect legacy dashboard to new sidebar layout
if ($page === 'dashboard' || $page === '') {
    header('Location: /admin-panel.php', true, 301);
    exit;
}
$campaignId = (int)($_GET['id'] ?? 0);

// ═══ Dashboard 数据 (先查，因为 Stats 详情也复用它) ───
$stats = ['clicks' => 0, 'conversions' => 0, 'revenue' => 0, 'profit' => 0];
$statsYesterday = ['clicks' => 0, 'conversions' => 0, 'revenue' => 0, 'profit' => 0];
$trendData = ['hours' => [], 'visits' => [], 'revenue' => []];
$insights = [];
try {
    // Today's stats
    $r = $db->query("SELECT COUNT(*) as c FROM clicks WHERE DATE(ts) = CURDATE()");
    if ($r) $stats['clicks'] = (int)$r->fetch_assoc()['c'];
    $r = $db->query("SELECT COUNT(*) as c FROM conversions WHERE DATE(created_at) = CURDATE() AND status = 'approved'");
    if ($r) $stats['conversions'] = (int)$r->fetch_assoc()['c'];
    $r = $db->query("SELECT COALESCE(SUM(cv.value),0) as revenue, COALESCE(SUM(cv.value),0) - COALESCE(SUM(cv.payout),0) as profit
         FROM conversions cv WHERE cv.status = 'approved' AND DATE(cv.created_at) = CURDATE()");
    if ($r) { $pnl = $r->fetch_assoc(); $stats['revenue'] = round((float)$pnl['revenue'], 2); $stats['profit'] = round((float)$pnl['profit'], 2); }

    // Yesterday's stats (for delta calc)
    $r = $db->query("SELECT COUNT(*) as c FROM clicks WHERE DATE(ts) = CURDATE() - INTERVAL 1 DAY");
    if ($r) $statsYesterday['clicks'] = (int)$r->fetch_assoc()['c'];
    $r = $db->query("SELECT COUNT(*) as c FROM conversions WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY AND status = 'approved'");
    if ($r) $statsYesterday['conversions'] = (int)$r->fetch_assoc()['c'];
    $r = $db->query("SELECT COALESCE(SUM(value),0) as revenue FROM conversions WHERE status = 'approved' AND DATE(created_at) = CURDATE() - INTERVAL 1 DAY");
    if ($r) $statsYesterday['revenue'] = round((float)$r->fetch_assoc()['revenue'], 2);

    // Trend: 24h hourly clicks + revenue
    $r = $db->query("SELECT DATE_FORMAT(ts, '%H:00') AS hour, COUNT(*) AS cnt FROM clicks WHERE ts >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY hour ORDER BY MIN(ts)");
    $clickMap = []; while ($row = $r->fetch_assoc()) $clickMap[$row['hour']] = (int)$row['cnt'];
    $r = $db->query("SELECT DATE_FORMAT(created_at, '%H:00') AS hour, COALESCE(SUM(value),0) AS rev FROM conversions WHERE status = 'approved' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY hour ORDER BY MIN(created_at)");
    $revMap = []; while ($row = $r->fetch_assoc()) $revMap[$row['hour']] = round((float)$row['rev'], 2);
    // Build 2-hour interval trend (12 data points)
    for ($h = 0; $h < 24; $h += 2) {
        $key = sprintf('%02d:00', $h);
        $trendData['hours'][] = $key;
        $trendData['visits'][] = $clickMap[$key] ?? 0;
        $trendData['revenue'][] = $revMap[$key] ?? 0;
    }

    // Insights: data-driven instead of hardcoded
    $totalCampaigns = 0; $activeCampaigns = 0;
    $r = $db->query('SELECT COUNT(*) as c FROM campaigns'); if ($r) $totalCampaigns = (int)$r->fetch_assoc()['c'];
    $r = $db->query("SELECT COUNT(*) as c FROM campaigns WHERE status = 'active'"); if ($r) $activeCampaigns = (int)$r->fetch_assoc()['c'];
    $cvr = $stats['clicks'] > 0 ? round($stats['conversions'] / $stats['clicks'] * 100, 1) : 0;
    $insights[] = $zh ? "今日数据: {$stats['clicks']} 点击 · {$stats['conversions']} 转化 · CVR {$cvr}% · 收入 \${$stats['revenue']}" : "Today: {$stats['clicks']} clicks · {$stats['conversions']} conversions · {$cvr}% CVR · \${$stats['revenue']} revenue";
    if ($activeCampaigns > 0) {
        $insights[] = $zh ? "{$activeCampaigns}/{$totalCampaigns} 个广告运行中" : "{$activeCampaigns}/{$totalCampaigns} campaigns active";
    }
    if ($statsYesterday['clicks'] > 0) {
        $clickDelta = round(($stats['clicks'] - $statsYesterday['clicks']) / $statsYesterday['clicks'] * 100, 1);
        $dir = $clickDelta >= 0 ? '↑' : '↓';
        $insights[] = $zh ? "点击较昨日 {$dir}{$clickDelta}%" : "Clicks {$dir}{$clickDelta}% vs yesterday";
    }
    if ($stats['clicks'] > 0) {
        $insights[] = $zh ? '打开报告查看趋势图和维度分布' : 'Open Reports to view trend charts and breakdowns';
    }
    // Sparkline: 7-day daily trend for KPI cards
    $sparkClicks = $sparkConvs = $sparkRev = array_fill(0, 7, 0);
    try {
        $sr = $db->query("SELECT DATE(ts) AS day, COUNT(*) AS cnt FROM clicks WHERE ts >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY day ORDER BY day");
        if ($sr) while ($row = $sr->fetch_assoc()) {
            $idx = (int)((strtotime($row['day']) - strtotime('-6 days')) / 86400);
            if ($idx >= 0 && $idx < 7) $sparkClicks[$idx] = (int)$row['cnt'];
        }
        $sr = $db->query("SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM conversions WHERE status='approved' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY day ORDER BY day");
        if ($sr) while ($row = $sr->fetch_assoc()) {
            $idx = (int)((strtotime($row['day']) - strtotime('-6 days')) / 86400);
            if ($idx >= 0 && $idx < 7) $sparkConvs[$idx] = (int)$row['cnt'];
        }
        $sr = $db->query("SELECT DATE(created_at) AS day, COALESCE(SUM(value),0) AS rev FROM conversions WHERE status='approved' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY day ORDER BY day");
        if ($sr) while ($row = $sr->fetch_assoc()) {
            $idx = (int)((strtotime($row['day']) - strtotime('-6 days')) / 86400);
            if ($idx >= 0 && $idx < 7) $sparkRev[$idx] = round((float)$row['rev'], 2);
        }
    } catch (\Throwable $e) {}
} catch (\Throwable $e) { error_log('Index dashboard error: ' . $e->getMessage()); }

$stats['roi'] = $stats['revenue'] > 0 ? round(($stats['profit'] / $stats['revenue']) * 100, 1) : 0;

// KPI delta helpers
function _delta(int|float $today, int|float $yesterday): array {
    if ($yesterday == 0) return $today > 0 ? ['change' => '+100%', 'trend' => 'up'] : ['change' => '0%', 'trend' => 'neutral'];
    $pct = round(($today - $yesterday) / $yesterday * 100, 1);
    return ['change' => ($pct >= 0 ? '+' : '') . $pct . '%', 'trend' => $pct >= 0 ? 'up' : 'down'];
}
$revDelta = _delta($stats['revenue'], $statsYesterday['revenue']);
$convDelta = _delta($stats['conversions'], $statsYesterday['conversions']);
$clickDelta = _delta($stats['clicks'], $statsYesterday['clicks']);

$kpis = [
    ['icon' => '💰', 'title' => $zh ? '今日收入' : 'Revenue',   'value' => $stats['revenue'],           'change' => $revDelta['change'],  'trend' => $revDelta['trend']],
    ['icon' => '✅', 'title' => $zh ? '转化数' : 'Conversions', 'value' => $stats['conversions'],        'change' => $convDelta['change'], 'trend' => $convDelta['trend']],
    ['icon' => '👆', 'title' => $zh ? '点击数' : 'Clicks',      'value' => $stats['clicks'],             'change' => $clickDelta['change'], 'trend' => $clickDelta['trend']],
    ['icon' => '📈', 'title' => 'ROI',                          'value' => $stats['roi'] . '%',          'change' => '',                   'trend' => 'neutral'],
];

// ═══ Campaign Stats 详情数据 ───
$campaignDetail = null;
if ($page === 'stats' && $campaignId > 0) {
    try {
        $stmt = $db->prepare('SELECT id, name, status, traffic_source, COALESCE(default_cpc,0) as budget_daily FROM campaigns WHERE id = ?');
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $campaignDetail = [
                'info' => $row + ['destination' => ''],
                'kpi' => $kpis,
                'hourly' => $trendData,
                'geo' => [],
                'funnel' => [],
                'conversions' => [],
                'dateRange' => ['from' => date('Y-m-d', strtotime('-7 days')), 'to' => date('Y-m-d')],
            ];
        }
    } catch (\Throwable $e) {}
}

// ═══ Campaigns 数据 + Sparkline ───
$campaigns = [];
try {
    $result = $db->query('SELECT id, name, status, traffic_source, COALESCE(default_cpc, 0) as budget_daily, updated_at FROM campaigns ORDER BY updated_at DESC LIMIT 50');
    if ($result) while ($row = $result->fetch_assoc()) {
        $row['traffic_source'] = $row['traffic_source'] ?? 'Direct';
        $row['budget_daily'] = (float)($row['budget_daily'] ?? 0);
        $row['sparkline'] = [0,0,0,0,0,0,0]; // default: 7 zeros
        $campaigns[] = $row;
    }

    // Batch query 7-day daily click trends for all campaigns
    if (!empty($campaigns)) {
        $cids = implode(',', array_column($campaigns, 'id'));
        $sr = $db->query(
            "SELECT campaign_id, DATE(ts) AS day, COUNT(*) AS cnt
             FROM clicks WHERE campaign_id IN ({$cids})
             AND ts >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY campaign_id, day ORDER BY campaign_id, day"
        );
        if ($sr) {
            $sparkMap = [];
            while ($srow = $sr->fetch_assoc()) {
                $sparkMap[(int)$srow['campaign_id']][$srow['day']] = (int)$srow['cnt'];
            }
            $today = date('Y-m-d');
            foreach ($campaigns as &$c) {
                $cid = (int)$c['id'];
                $vals = [];
                for ($d = 6; $d >= 0; $d--) {
                    $day = date('Y-m-d', strtotime("-{$d} days"));
                    $vals[] = $sparkMap[$cid][$day] ?? 0;
                }
                $c['sparkline'] = $vals;
                $c['sparkline_max'] = max($vals) ?: 1;
            }
        }
    }
} catch (\Throwable $e) {}

$campaignStats = [
    ['icon' => '📋', 'value' => (string)count($campaigns), 'label' => $zh ? '全部广告' : 'Total'],
    ['icon' => '🟢', 'value' => (string)count(array_filter($campaigns, fn($c) => ($c['status'] ?? '') === 'active')), 'label' => $zh ? '运行中' : 'Active'],
    ['icon' => '⏸️', 'value' => (string)count(array_filter($campaigns, fn($c) => ($c['status'] ?? '') === 'paused')), 'label' => $zh ? '已暂停' : 'Paused'],
];

// ═══ Activity feed (from audit log) ───
$recentActivity = [];
try {
    $r = $db->query('SELECT action, detail, created_at FROM admin_audit_log ORDER BY created_at DESC LIMIT 8');
    while ($row = $r->fetch_assoc()) {
        $ts = strtotime($row['created_at']);
        $ago = time() - $ts;
        $timeStr = $ago < 60 ? 'just now' : ($ago < 3600 ? floor($ago / 60) . 'm ago' : ($ago < 86400 ? floor($ago / 3600) . 'h ago' : date('m-d H:i', $ts)));
        $icon = match(true) {
            str_contains($row['action'], 'conversion') || str_contains($row['action'], 'approved') => '✅',
            str_contains($row['action'], 'auto_pause') || str_contains($row['action'], 'pause') => '⏸️',
            str_contains($row['action'], 'auto_rules') || str_contains($row['action'], 'rule') => '🤖',
            default => '📋',
        };
        $recentActivity[] = ['icon' => $icon, 'text' => $row['detail'], 'time' => $timeStr];
    }
} catch (\Throwable $e) {}
// Fallback if no activity
if (empty($recentActivity)) {
    $recentActivity = [
        ['icon' => '📋', 'text' => $zh ? '暂无活动记录' : 'No activity yet', 'time' => ''],
        ['icon' => '🤖', 'text' => $zh ? '自动规则引擎就绪' : 'Auto rules engine ready', 'time' => ''],
    ];
}

// ═══ Refund Stats (CAPI write-back) ───
$refundStats = ['total' => 0, 'recent' => [], 'meta_count' => 0, 'tiktok_count' => 0, 'google_count' => 0];
try {
    $rr = $db->query('SELECT ca.id, ca.conversion_id, ca.amount, ca.type, ca.meta_sent, ca.tiktok_sent, ca.google_sent, ca.created_at, COALESCE(c.payout,0) as payout FROM conversion_adjustments ca LEFT JOIN conversions c ON ca.conversion_id = c.id ORDER BY ca.created_at DESC LIMIT 5');
    if ($rr) while ($row = $rr->fetch_assoc()) {
        $refundStats['total']++;
        if ($row['meta_sent']) $refundStats['meta_count']++;
        if ($row['tiktok_sent']) $refundStats['tiktok_count']++;
        if ($row['google_sent']) $refundStats['google_count']++;
        $ts = strtotime($row['created_at']);
        $ago = time() - $ts;
        $row['time_ago'] = $ago < 60 ? 'just now' : ($ago < 3600 ? floor($ago/60).'m ago' : ($ago < 86400 ? floor($ago/3600).'h ago' : date('m-d H:i', $ts)));
        $refundStats['recent'][] = $row;
    }
} catch (\Throwable $e) { error_log('Refund stats error: ' . $e->getMessage()); }

// ═══ Flow Builder data ───
$landingPages = []; $offers = [];
try {
    $r = $db->query('SELECT id, name, url, status FROM landing_pages ORDER BY name LIMIT 50');
    while ($row = $r->fetch_assoc()) $landingPages[] = $row;
} catch (\Throwable $e) {}
try {
    $r = $db->query('SELECT id, name, url, payout, status FROM offers ORDER BY name LIMIT 50');
    while ($row = $r->fetch_assoc()) $offers[] = $row;
} catch (\Throwable $e) {}

// ═══ 渲染 ───
LatteEngine::display('index', [
    'lang'       => $lang,
    'page'       => $page,
    'title'      => 'Converge',
    'user'       => $currentUser ?: ['name' => 'Admin', 'role' => 'Admin'],
    'roles'      => ['Admin', 'Manager', 'Buyer'],
    'headExtra'  => '',
    // Dashboard
    'kpis'       => $kpis,
    'insights'   => $insights,
    'trendData'  => $trendData,
    'trendJson'  => json_encode($trendData),
    'recentActivity' => $recentActivity,
    'refundStats'    => $refundStats,
    // Campaigns
    'campaigns'      => $campaigns,
    'campaignsJson'  => json_encode($campaigns, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG),
    'stats'          => $campaignStats,
    'newCampaignLabel' => $zh ? '新建广告' : 'New Campaign',
    'pageInfoJson'   => json_encode(['page' => 1, 'perPage' => 10, 'total' => count($campaigns)]),
    // Campaign Stats (详情页)
    'campaign'       => $campaignDetail,
    'chartJson'      => json_encode($campaignDetail['hourly'] ?? $trendData),
    // Flow Builder
    'flowData'       => [
        'campaigns'    => $campaigns,
        'landingPages' => $landingPages,
        'offers'       => $offers,
        'paths'        => [],
        'flowType'     => 'redirect',
    ],
]);
