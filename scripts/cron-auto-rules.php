<?php
/**
 * cron-auto-rules.php — Auto Rules Engine (DB-driven + hardcoded fallback)
 *
 * Evaluates auto_rules table + built-in conversion cap check.
 * Crontab: every 10 minutes via php scripts/cron-auto-rules.php
 * Usage: php scripts/cron-auto-rules.php [--dry]
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

$db = db()->raw();
$dry = in_array('--dry', $argv ?? []);

$notifier = class_exists('Converge\Foundation\Observability\AlertNotifier')
    ? new Converge\Foundation\Observability\AlertNotifier() : null;

echo date('Y-m-d H:i:s') . " — Auto Rules Engine\n";

$actions = 0;

// ═══ Phase 1: DB-driven rules from auto_rules table ═══
$tableExists = $db->query("SHOW TABLES LIKE 'auto_rules'");
if ($tableExists && $tableExists->num_rows > 0) {
    $rules = $db->query(
        "SELECT r.*, c.name AS campaign_name, c.status AS campaign_status
         FROM auto_rules r LEFT JOIN campaigns c ON r.campaign_id = c.id
         WHERE r.enabled = 1
         AND (r.last_run_at IS NULL OR r.last_run_at < DATE_SUB(NOW(), INTERVAL r.check_window_minutes MINUTE))"
    );

    if ($rules) {
        while ($rule = $rules->fetch_assoc()) {
            $rid = (int)$rule['id'];
            $rname = $rule['name'];
            $cid = $rule['campaign_id'] ? (int)$rule['campaign_id'] : null;
            $metric = $rule['metric'];
            $operator = $rule['operator'];
            $threshold = (float)$rule['threshold'];
            $action = $rule['action'];
            $actionValue = $rule['action_value'] ? (float)$rule['action_value'] : null;
            $window = (int)($rule['check_window_minutes'] ?? 1440);

            // Build campaign filter
            $campFilter = $cid ? "AND c.id = {$cid}" : "AND c.status = 'active'";
            $since = "DATE_SUB(NOW(), INTERVAL {$window} MINUTE)";

            // Query current metric value
            $sql = match ($metric) {
                'clicks' => "SELECT COUNT(*) AS val FROM clicks cl JOIN campaigns c ON cl.campaign_id=c.id WHERE cl.ts >= {$since} {$campFilter}",
                'conversions' => "SELECT COUNT(*) AS val FROM conversions cv JOIN clicks cl ON cv.click_id=cl.click_id JOIN campaigns c ON cl.campaign_id=c.id WHERE cv.status='approved' AND cv.created_at >= {$since} {$campFilter}",
                'cost' => "SELECT COALESCE(SUM(cl.cost),0) AS val FROM clicks cl JOIN campaigns c ON cl.campaign_id=c.id WHERE cl.ts >= {$since} {$campFilter}",
                'revenue' => "SELECT COALESCE(SUM(cv.payout),0) AS val FROM conversions cv JOIN clicks cl ON cv.click_id=cl.click_id JOIN campaigns c ON cl.campaign_id=c.id WHERE cv.status='approved' AND cv.created_at >= {$since} {$campFilter}",
                'roas' => "SELECT CASE WHEN COALESCE(SUM(cl.cost),0)>0 THEN ROUND(COALESCE(SUM(cv.payout),0)/SUM(cl.cost)*100,1) ELSE 0 END AS val FROM campaigns c LEFT JOIN clicks cl ON cl.campaign_id=c.id AND cl.ts >= {$since} LEFT JOIN conversions cv ON cv.click_id=cl.click_id AND cv.status='approved' WHERE 1=1 {$campFilter}",
                default => "SELECT COUNT(*) AS val FROM clicks cl JOIN campaigns c ON cl.campaign_id=c.id WHERE cl.ts >= {$since} {$campFilter}",
            };

            $qr = $db->query($sql);
            if (!$qr) continue;
            $current = (float)$qr->fetch_assoc()['val'];

            // Evaluate condition
            $triggered = match ($operator) {
                '>' => $current > $threshold,
                '<' => $current < $threshold,
                '>=' => $current >= $threshold,
                '<=' => $current <= $threshold,
                '==' => abs($current - $threshold) < 0.0001,
                default => false,
            };

            if (!$triggered) continue;

            // Execute action
            $cname = $rule['campaign_name'] ?? 'GLOBAL';
            echo "  🔔 Rule #{$rid} '{$rname}' triggered: {$metric}{$operator}{$threshold} (current={$current}) on {$cname}\n";

            if ($dry) {
                echo "    [DRY] Would execute: {$action}\n";
                continue;
            }

            match ($action) {
                'pause' => (function() use ($db, $cid, $rid, $rname) {
                    $db->query("UPDATE campaigns SET status='paused', updated_at=NOW() WHERE id={$cid}");
                    $db->query("INSERT INTO admin_audit_log (user_id, action, detail, created_at) VALUES ('auto_rules', 'auto_pause', 'Rule #{$rid}: {$rname}', NOW())");
                })(),
                'notify' => (function() use ($notifier, $cid, $rname, $metric, $current, $threshold) {
                    if ($notifier) $notifier->send("Auto Rule '{$rname}': {$metric}={$current} (threshold={$threshold}) on campaign #{$cid}", 'warning', ['rule'=>$rname,'campaign_id'=>$cid]);
                })(),
                default => null,
            };

            // Update last_run
            $db->query("UPDATE auto_rules SET last_run_at=NOW(), run_count=run_count+1 WHERE id={$rid}");
            $actions++;
        }
    }
}

// ═══ Phase 2: Conversion Cap (hardcoded safety net) ═══
$capCampaigns = $db->query(
    "SELECT c.id, c.name, c.conversion_cap,
       (SELECT COUNT(*) FROM conversions WHERE click_id IN (SELECT click_id FROM clicks WHERE campaign_id = c.id) AND status = 'approved') as convs
     FROM campaigns c WHERE c.status = 'active' AND c.conversion_cap > 0"
);
if ($capCampaigns) {
    while ($cc = $capCampaigns->fetch_assoc()) {
        $cap = (int)$cc['conversion_cap'];
        $convs = (int)$cc['convs'];
        if ($convs >= $cap) {
            if (!$dry) {
                $db->query("UPDATE campaigns SET status = 'paused', updated_at = NOW() WHERE id = {$cc['id']}");
                $db->query("INSERT INTO admin_audit_log (user_id, action, detail, created_at) VALUES ('auto_rules', 'auto_pause_cap', 'Campaign #{$cc['id']}: cap {$cap} reached ({$convs} conversions)', NOW())");
            }
            echo "  🎯 Campaign {$cc['id']} ({$cc['name']}): CAP REACHED — {$convs}/{$cap} conversions, PAUSED\n";
            if (!$dry && $notifier) $notifier->send("Campaign #{$cc['id']} ({$cc['name']}) conversion cap reached: {$convs}/{$cap}", 'warning', ['campaign_id' => $cc['id']]);
            $actions++;
        }
    }
}

// ═══ Phase 3: Stop-Loss — Budget Reallocation + LP Circuit Breaker ═══
if (class_exists('Converge\Evolution\StopLossEngine')) {
    $stopLoss = new \Converge\Evolution\StopLossEngine($db, $dry);
    $stopLog = $stopLoss->run();
    foreach ($stopLog as $entry) { echo "  {$entry}\n"; }
    $actions += count($stopLog);
}

// ═══ Phase 4: Dead Letter Retry (EventStore failed writes) ═══
if (class_exists('Converge\Traceability\Infrastructure\EventStore')) {
    try {
        $esPath = defined('STORAGE_PATH') ? STORAGE_PATH . '/events.sqlite' : __DIR__ . '/../storage/events.sqlite';
        if (file_exists($esPath)) {
            $es = new \Converge\Traceability\Infrastructure\EventStore($esPath);
            $pending = $es->getDeadLettersForRetry(20);
            $retried = 0;
            foreach ($pending as $dl) {
                if ($dry) { echo "  [DRY] Would retry dead letter #{$dl['id']}: {$dl['event_type']}\n"; continue; }
                try {
                    $es->append($dl['aggregate_id'], $dl['event_type'],
                        json_decode($dl['payload'], true) ?: []);
                    $es->resolveDeadLetter((int)$dl['id'], true);
                    $retried++;
                    echo "  ✅ Dead letter #{$dl['id']} retried: {$dl['event_type']}\n";
                } catch (\Throwable $e) {
                    $es->resolveDeadLetter((int)$dl['id'], false, $dl['retries'] + 1);
                    echo "  ❌ Dead letter #{$dl['id']} failed (retry {$dl['retries']}): {$e->getMessage()}\n";
                }
            }
            if ($retried > 0) echo "  Dead letters retried: {$retried}\n";

            // Alert on dead letter backlog
            $backlog = $es->getDeadLetterStats();
            if (($backlog['pending'] ?? 0) > 1000 && $notifier) {
                $notifier->send(
                    "⚠️ Dead Letter backlog: {$backlog['pending']} pending events. Investigate EventStore health.",
                    'warning', ['backlog' => $backlog['pending']]
                );
            } elseif (($backlog['pending'] ?? 0) > 100) {
                echo "  ⚠️ Dead letter backlog: {$backlog['pending']} pending\n";
            }
        }
    } catch (\Throwable $e) {
        echo "  ⚠️ Dead letter retry skipped: {$e->getMessage()}\n";
    }
}

echo "  Done: {$actions} actions\n" . ($dry ? "  (dry-run mode)\n" : "");
