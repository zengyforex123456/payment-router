<?php

declare(strict_types=1);

/**
 * verify-feature-gating.php — Phase A: FeatureRegistry 激活验收 (#51)
 * 独立运行: php verify-feature-gating.php → 期望 PASS=N FAIL=0
 *
 * 覆盖(纯逻辑, 无DB): 自初始化(ensureBootstrapped) + resolvePlan(0)免费路径 +
 *   plan映射 + override紧急开关 + guard动态判断 + planCache.
 * SaaS pro租户(tenantId>0→saas_tenants join)由服务器 DB 冒烟覆盖, 见部署脚本.
 */

require_once __DIR__ . '/kernel/src/Foundation/System/FeatureRegistry.php';

use Converge\Foundation\System\FeatureRegistry;

$pass = 0;
$fail = 0;
function check(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name}\n"; }
}

echo "== 自初始化 (bootstrap 从没显式调用, isEnabled/all 应自触发) ==\n";
// 未显式 bootstrap() 就直接查 → ensureBootstrapped 应已填充 11 features
$all = FeatureRegistry::all();
check('all() 自初始化后非空', count($all) >= 11);
check('含 smart_rotation 定义', isset($all['smart_rotation']));
check('含 bot_detection 定义', isset($all['bot_detection']));

echo "== resolvePlan(0) 免费路径 + plan 映射 (无 LICENSE_KEY → free) ==\n";
check('Pro功能 smart_rotation 对 free(tid0) → false', FeatureRegistry::isEnabled('smart_rotation', 0) === false);
check('Pro功能 ab_test 默认(tid0) → false', FeatureRegistry::isEnabled('ab_test') === false);
check('Pro功能 advanced_attribution → false', FeatureRegistry::isEnabled('advanced_attribution', 0) === false);
check('免费功能 bot_detection 含free → true', FeatureRegistry::isEnabled('bot_detection', 0) === true);
check('免费功能 event_store 含free → true', FeatureRegistry::isEnabled('event_store', 0) === true);
check('enterprise-only white_label 对free → false', FeatureRegistry::isEnabled('white_label', 0) === false);
check('未知功能 → false(fail-closed)', FeatureRegistry::isEnabled('nonexistent_xyz', 0) === false);

echo "== override 紧急开关 ==\n";
FeatureRegistry::disable('bot_detection');
check('disable 后 bot_detection → false(秒关)', FeatureRegistry::isEnabled('bot_detection', 0) === false);
FeatureRegistry::enable('bot_detection');
check('enable 后 bot_detection → 恢复 true', FeatureRegistry::isEnabled('bot_detection', 0) === true);

echo "== guard 动态判断 (绕过 plan) ==\n";
FeatureRegistry::guard('vip_test', fn(int $t) => $t === 42);
check('guard 命中租户42 → true', FeatureRegistry::isEnabled('vip_test', 42) === true);
check('guard 未命中租户1 → false', FeatureRegistry::isEnabled('vip_test', 1) === false);

echo "== planCache 可重置 ==\n";
FeatureRegistry::resetPlanCache();
check('resetPlanCache 后仍正常判定', FeatureRegistry::isEnabled('smart_rotation', 0) === false);

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
