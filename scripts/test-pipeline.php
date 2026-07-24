<?php
/** 全链路测试: EventStore + ShadowMode + ApiRegistry */
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../kernel/src/Foundation/Database/db.php';

use Converge\Traceability\Infrastructure\EventStore;
use Converge\Foundation\Resilience\ShadowMode;
use Converge\Foundation\Contract\ApiRegistry;

$db = db()->raw();

// ① EventStore: append + trace + query
echo "=== ① EventStore ===\n";
$store = new EventStore($db);
$id1 = $store->append(EventStore::TYPE_USER_LOGIN, 'u1', ['ip' => '127.0.0.1']);
$id2 = $store->append(EventStore::TYPE_CAMPAIGN_CREATE, 'u1', ['name' => 'Test'], (string)$id1);
$id3 = $store->append(EventStore::TYPE_DEPLOY_SUCCESS, 'sys', ['v' => '2.5'], (string)$id2);
echo "  Events: {$id1} → {$id2} → {$id3}\n";
echo "  Count: " . $store->count() . "\n";
$trace = $store->trace((string)$id3);
echo "  Trace depth: " . count($trace) . "\n";

// ② ShadowMode: register → 4 cycles → graduate
echo "\n=== ② ShadowMode ===\n";
$sm = new ShadowMode($db);
$sm->register('test-feature', ['desc' => 'E2E test']);
for ($i = 1; $i <= 4; $i++) {
    $sm->recordCycle('test-feature', ['out' => 'ok'], ['out' => 'ok']);
}
$f = $sm->get('test-feature');
echo "  Phase: {$f['phase']} | Cycles: {$f['cycles_completed']}\n";
if ($sm->canGraduate('test-feature')) {
    $sm->graduate('test-feature');
    echo "  ✅ Graduated → active\n";
}

// ③ ApiRegistry
echo "\n=== ③ ApiRegistry ===\n";
$s = ApiRegistry::stats();
echo "  {$s['total']} actions, {$s['implemented']} implemented, {$s['placeholders']} placeholders\n";

// Cleanup
$db->query("DELETE FROM event_store WHERE aggregate_id IN ('u1','sys')");
$db->query("DELETE FROM shadow_features WHERE name = 'test-feature'");

echo "\n✅ 全链路通过\n";
