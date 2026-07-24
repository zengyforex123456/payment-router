<?php

declare(strict_types=1);

/**
 * verify-tenant-p0.php — P0 地基止血纯函数验收 (PRD R1/R3)
 *
 * 独立运行: php verify-tenant-p0.php  → 期望 PASS=N FAIL=0
 * 覆盖: TenantContext 守卫路径 + 迁移表清单 (不需 DB).
 * DB 实际隔离由部署后 E2E (verify-tenant-isolation, P4) 覆盖.
 */

require_once __DIR__ . '/app/SaaS/TenantContext.php';
require_once __DIR__ . '/app/Database/Migrations/TenantIdFirstClass.php';

use Converge\Modules\SaasReferral\TenantContext;
use Converge\Database\Migrations\TenantIdFirstClass;

$pass = 0;
$fail = 0;
function check(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}\n";
    }
}

echo "== TenantContext: session 单一源 ==\n";
$_SESSION = [];
check('current() 无 session → 0', TenantContext::current() === 0);
check('has() 无 session → false', TenantContext::has() === false);
$_SESSION['tenant_id'] = 7;
check('current() 读 session=7', TenantContext::current() === 7);
check('has() tenant>0 → true', TenantContext::has() === true);
$_SESSION['tenant_id'] = '0';
check('current() 字符串0 → int 0', TenantContext::current() === 0);

echo "== TenantContext: 写路径守卫 (无 DB 调用) ==\n";
$db = mysqli_init(); // 未连接; campaignId<=0 / clickId='' 在触库前短路
check('forCampaign(db, 0) → 0 守卫', TenantContext::forCampaign($db, 0) === 0);
check('forCampaign(db, -5) → 0 守卫', TenantContext::forCampaign($db, -5) === 0);
check('forClickId(db, "") → 0 守卫', TenantContext::forClickId($db, '') === 0);
TenantContext::resetCache();
check('resetCache() 可调用', true);

echo "== TenantIdFirstClass: 迁移表清单 ==\n";
$ref = new ReflectionClass(TenantIdFirstClass::class);
$tenantTables = $ref->getConstant('TENANT_TABLES');
$ownedTables = $ref->getConstant('OWNED_TABLES');
check('隔离表含 clicks', in_array('clicks', $tenantTables, true));
check('隔离表含 conversions', in_array('conversions', $tenantTables, true));
check('隔离表含 campaigns', in_array('campaigns', $tenantTables, true));
check('created_by 表含 campaigns', in_array('campaigns', $ownedTables, true));
check('created_by 表不含 clicks (访客无创建者)', !in_array('clicks', $ownedTables, true));
check('run() 方法存在', method_exists(TenantIdFirstClass::class, 'run'));

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
