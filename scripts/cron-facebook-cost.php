<?php
/** cron-facebook-cost.php — Facebook/Google Ads 花费同步 */
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Contracts/bootstrap.php';

echo date('Y-m-d H:i:s') . " — Cost Sync\n";

$db = db()->raw();

// Fetch campaigns with Facebook integration
$campaigns = $db->query(
    "SELECT id, name, facebook_capi_integration_id, facebook_marketing_campaign_id
     FROM campaigns WHERE status = 'active' AND facebook_capi_integration_id > 0"
);

if (!$campaigns || $campaigns->num_rows === 0) {
    echo "  No Facebook-linked campaigns\n";
    exit(0);
}

$synced = 0;
while ($c = $campaigns->fetch_assoc()) {
    try {
        // 通过 ContractBus 获取 — 换平台只改 bootstrap 绑定
        if (\Converge\Foundation\ContractBus::has(\Converge\Contracts\Cost\CostFetcherInterface::class)) {
            $fetcher = \Converge\Foundation\ContractBus::get(\Converge\Contracts\Cost\CostFetcherInterface::class);
            $cost = $fetcher->fetch((int)$c['id']);
            if ($cost) {
                // Update clicks.cost for this campaign
                $stmt = $db->prepare('UPDATE clicks SET cost = cost + ?, cost_currency = ? WHERE campaign_id = ? AND DATE(ts) = CURDATE()');
                $cpc = $cost['cpc'] ?? $cost['spend'] / max(1, $cost['clicks']);
                $stmt->bind_param('dsi', $cpc, $cost['currency'] ?? 'USD', (int)$c['id']);
                $stmt->execute();
                $synced++;
                echo "  ✅ Campaign {$c['id']} ({$c['name']}): \${$cost['spend']}\n";
            }
        } else {
            echo "  FacebookCost module not available\n";
            break;
        }
    } catch (\Throwable $e) {
        echo "  ⚠️ Campaign {$c['id']}: {$e->getMessage()}\n";
    }
}

echo "  Done: {$synced} synced\n";
