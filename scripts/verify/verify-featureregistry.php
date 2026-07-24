<?php
// verify-featureregistry.php — 独立验证 FeatureRegistry::bootstrap 行为正确 (接线前的证明)
// 不碰 DB/config: tenantId=0 且无 LICENSE_KEY → resolvePlan 回落 'free', 纯逻辑可测。
// 跑: C:\tools\php82\php.exe verify-featureregistry.php  →  期望 PASS=N FAIL=0
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use Converge\Foundation\System\FeatureRegistry;

$pass = 0; $fail = 0;
function check(string $desc, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "[PASS] " : "[FAIL] ") . $desc . "\n";
    $ok ? $pass++ : $fail++;
}

FeatureRegistry::bootstrap();
$all = FeatureRegistry::all(0); // tenantId=0 + 无 LICENSE_KEY → 'free' 上下文

// 1. 11 个功能全注册
check("bootstrap 注册 11 个功能 (实得 " . count($all) . ")", count($all) === 11);

// 2. free 上下文: Pro-only 功能必须 OFF (防误解锁)
foreach (['smart_rotation', 'ab_test', 'advanced_attribution', 'funnel_analytics', 'ooda_learning', 'api_access'] as $f) {
    check("free上下文 {$f} = 关(Pro锁)", isset($all[$f]) && $all[$f]['enabled'] === false);
}

// 3. free 上下文: 免费层功能必须 ON (基础设施)
foreach (['bot_detection', 'health_monitoring', 'event_store'] as $f) {
    check("free上下文 {$f} = 开(免费层)", isset($all[$f]) && $all[$f]['enabled'] === true);
}

// 4. enterprise-only 功能对 free = 关
foreach (['clickhouse', 'white_label'] as $f) {
    check("free上下文 {$f} = 关(Ent锁)", isset($all[$f]) && $all[$f]['enabled'] === false);
}

// 5. 注册正确性: Pro 功能的 plans = pro+enterprise (证明 pro 上下文会放行)
check("smart_rotation.plans = [pro,enterprise]", ($all['smart_rotation']['plans'] ?? []) === ['pro', 'enterprise']);
check("clickhouse.plans = [enterprise]", ($all['clickhouse']['plans'] ?? []) === ['enterprise']);

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
