#!/usr/bin/env php
<?php
/**
 * feature-usage-report.php — 功能使用量诊断报告
 * 输出每个页面/API/模块的使用频率，辅助价值评估
 *
 * 用法: php scripts/feature-usage-report.php [--json] [--days=30]
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

$json = in_array('--json', $argv);
$days = 30;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--days=')) $days = (int) substr($arg, 7);
}

// ═══ 1. 数据库连接 ═══
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $db->set_charset(DB_CHARSET);
} catch (\Throwable $e) {
    die("❌ DB connection failed: " . $e->getMessage() . "\n");
}

$report = [];

// ═══ 2. 页面访问量 (audit_log) ═══
try {
    $r = $db->query("
        SELECT entity_type AS page, COUNT(*) AS visits,
               MAX(created_at) AS last_access
        FROM audit_log
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY entity_type
        ORDER BY visits DESC
    ");
    $report['pages'] = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
} catch (\Throwable) { $report['pages'] = []; }

// ═══ 3. 核心业务数据量 ═══
try {
    $report['data_volume'] = [];
    foreach (['clicks', 'conversions', 'campaigns', 'offers', 'landing_pages', 'networks'] as $t) {
        $r = $db->query("SELECT COUNT(*) as cnt FROM `{$t}`");
        $report['data_volume'][$t] = $r ? (int) $r->fetch_assoc()['cnt'] : 0;
    }
} catch (\Throwable) { $report['data_volume'] = []; }

// ═══ 4. Campaign 活跃度 ═══
try {
    $r = $db->query("SELECT status, COUNT(*) as cnt FROM campaigns GROUP BY status");
    $report['campaign_status'] = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $r = $db->query("
        SELECT c.id, c.name,
               (SELECT COUNT(*) FROM clicks WHERE campaign_id=c.id AND ts >= DATE_SUB(NOW(), INTERVAL {$days} DAY)) AS recent_clicks
        FROM campaigns c
        HAVING recent_clicks = 0
        ORDER BY c.id
    ");
    $report['idle_campaigns'] = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
} catch (\Throwable) { $report['campaign_status'] = []; $report['idle_campaigns'] = []; }

// ═══ 5. API 端点调用频率 ═══
try {
    $r = $db->query("
        SELECT SUBSTRING_INDEX(entity_type, '::', 1) AS endpoint, COUNT(*) AS calls
        FROM audit_log
        WHERE entity_type LIKE 'api:%' AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY endpoint ORDER BY calls DESC
    ");
    $report['api_calls'] = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
} catch (\Throwable) { $report['api_calls'] = []; }

// ═══ 6. 零引用文件检测 ═══
$report['unreferenced_files'] = [];
$publicPhp = glob(__DIR__ . '/../public/*.php');
foreach ($publicPhp as $file) {
    $basename = basename($file);
    // Skip known entry points
    if (in_array($basename, ['index.php', 'config.php', 'precision-data.php'])) continue;
    // Check if referenced by any other PHP file
    $referenced = false;
    foreach ($publicPhp as $other) {
        if ($other === $file) continue;
        $content = file_get_contents($other);
        if (str_contains($content, $basename)) { $referenced = true; break; }
    }
    if (!$referenced) {
        // Also check templates
        $templates = glob(__DIR__ . '/../templates/**/*.latte');
        foreach ($templates as $tpl) {
            if (str_contains(file_get_contents($tpl), $basename)) { $referenced = true; break; }
        }
    }
    if (!$referenced) $report['unreferenced_files'][] = $basename;
}

// ═══ 输出 ═══
if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "╔══════════════════════════════════════════════════╗\n";
    echo "║   Converge Feature Usage Report ({$days}d)       ║\n";
    echo "╚══════════════════════════════════════════════════╝\n\n";

    echo "📊 数据量:\n";
    foreach ($report['data_volume'] as $table => $cnt) {
        printf("  %-20s %8d rows\n", $table, $cnt);
    }

    echo "\n📈 Campaign 状态:\n";
    foreach ($report['campaign_status'] as $s) {
        printf("  %-20s %8d\n", $s['status'], $s['cnt']);
    }

    echo "\n💤 零活跃 Campaign (近{$days}天无点击): " . count($report['idle_campaigns']) . "\n";
    foreach (array_slice($report['idle_campaigns'], 0, 10) as $c) {
        printf("  #%d %s\n", $c['id'], $c['name']);
    }

    echo "\n🌐 页面访问 Top 10:\n";
    $i = 0;
    foreach ($report['pages'] as $p) {
        printf("  %3d visits  %-40s last: %s\n", $p['visits'], $p['page'], $p['last_access'] ?? '?');
        if (++$i >= 10) break;
    }

    echo "\n🔌 API 调用 Top 10:\n";
    $i = 0;
    foreach ($report['api_calls'] as $a) {
        printf("  %3d calls   %s\n", $a['calls'], $a['endpoint']);
        if (++$i >= 10) break;
    }

    echo "\n⚠️ 疑似孤儿文件 (零引用): " . count($report['unreferenced_files']) . "\n";
    foreach ($report['unreferenced_files'] as $f) {
        echo "  - $f\n";
    }

    echo "\n💡 评估建议:\n";
    $orphanCount = count($report['unreferenced_files']);
    $idleCount = count($report['idle_campaigns']);
    if ($orphanCount > 5) echo "  🔴 {$orphanCount} 个页面可能无调用，复查后可删除\n";
    if ($idleCount > 0) echo "  🟡 {$idleCount} 个 Campaign 长期无点击，建议暂停清理\n";
    echo "  运行 php scripts/feature-usage-report.php --json 获取机器可读格式\n";
}

$db->close();
