<?php

declare(strict_types=1);

/**
 * verify-tenant-p2.php — P2 收口层 scopeClause 验收 (PRD R4/R5)
 * 独立运行: php verify-tenant-p2.php → 期望 PASS=N FAIL=0
 * 覆盖: 安全阀(tenant0不过滤) + 别名前缀 + 插值正确. DB级IDOR由部署后E2E覆盖.
 */

require_once __DIR__ . '/app/SaaS/TenantContext.php';

use Converge\Modules\SaasReferral\TenantContext;

$pass = 0;
$fail = 0;
function check(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name}\n"; }
}

echo "== scopeClause 安全阀 (tenant<=0 不过滤) ==\n";
$_SESSION = [];
check('tenant 无 → 空串(不过滤,避免数据消失)', TenantContext::scopeClause() === '');
$_SESSION['tenant_id'] = 0;
check('tenant 0 → 空串', TenantContext::scopeClause('c') === '');

echo "== scopeClause 隔离生效 (tenant>0) ==\n";
$_SESSION['tenant_id'] = 3;
check('无别名 → " AND tenant_id = 3"', TenantContext::scopeClause() === ' AND tenant_id = 3');
check('别名c → " AND c.tenant_id = 3"', TenantContext::scopeClause('c') === ' AND c.tenant_id = 3');
check('别名o → " AND o.tenant_id = 3"', TenantContext::scopeClause('o') === ' AND o.tenant_id = 3');
$_SESSION['tenant_id'] = '7';
check('字符串租户 → 转int插值', TenantContext::scopeClause() === ' AND tenant_id = 7');

echo "== 拼接进 SQL 形态正确 ==\n";
$_SESSION['tenant_id'] = 3;
$sql = "SELECT * FROM networks WHERE id = ?" . TenantContext::scopeClause();
check('getById 形态', $sql === 'SELECT * FROM networks WHERE id = ? AND tenant_id = 3');
$sql2 = "SELECT * FROM networks WHERE 1=1" . TenantContext::scopeClause() . " ORDER BY created_at DESC";
check('getAll 形态', $sql2 === 'SELECT * FROM networks WHERE 1=1 AND tenant_id = 3 ORDER BY created_at DESC');
$_SESSION['tenant_id'] = 0;
$sql3 = "SELECT * FROM networks WHERE 1=1" . TenantContext::scopeClause() . " ORDER BY created_at DESC";
check('getAll tenant0 → 无过滤仍合法SQL', $sql3 === 'SELECT * FROM networks WHERE 1=1 ORDER BY created_at DESC');

echo "== P2b: scopeCondition (数组WHERE) ==\n";
$_SESSION['tenant_id'] = 0;
check('tenant0 → null(调用方跳过)', TenantContext::scopeCondition('conv') === null);
$_SESSION['tenant_id'] = 3;
check('tenant3 conv → "conv.tenant_id = 3"', TenantContext::scopeCondition('conv') === 'conv.tenant_id = 3');
check('tenant3 无别名 → "tenant_id = 3"', TenantContext::scopeCondition() === 'tenant_id = 3');

echo "== P2b: campaignScopeCondition (视图/汇总表, 无tenant_id列) ==\n";
$_SESSION['tenant_id'] = 0;
check('tenant0 → null', TenantContext::campaignScopeCondition('cl') === null);
$_SESSION['tenant_id'] = 3;
check('tenant3 cl → campaign_id 子查询', TenantContext::campaignScopeCondition('cl') === 'cl.campaign_id IN (SELECT id FROM campaigns WHERE tenant_id = 3)');

echo "== P2c: setTenant 显式覆盖 (API-key 无 session) ==\n";
$_SESSION = [];
TenantContext::resetCache();
check('无 session 无覆盖 → 0', TenantContext::current() === 0);
TenantContext::setTenant(5);
check('setTenant(5) → current()=5 (覆盖生效)', TenantContext::current() === 5);
check('覆盖后 scopeClause 生效', TenantContext::scopeClause('c') === ' AND c.tenant_id = 5');
$_SESSION['tenant_id'] = 9;
check('覆盖优先于 session', TenantContext::current() === 5);
TenantContext::setTenant(0);
check('setTenant(0) 清除覆盖 → 回落 session=9', TenantContext::current() === 9);
TenantContext::resetCache();
check('resetCache 清覆盖 → session=9', TenantContext::current() === 9);

echo "== P2d: 收口洞修补 (users表 + 日志/CSV导出新增 call site) ==\n";
$_SESSION = ['tenant_id' => 3];
TenantContext::resetCache();
check('users 别名u → " AND u.tenant_id = 3" (getAll/getById)', TenantContext::scopeClause('u') === ' AND u.tenant_id = 3');
check('clicks 别名cl → "cl.tenant_id = 3" (visitors/CSV)', TenantContext::scopeCondition('cl') === 'cl.tenant_id = 3');
check('conv 日志路径 → "conv.tenant_id = 3" (listConversionsForLog)', TenantContext::scopeCondition('conv') === 'conv.tenant_id = 3');
$_SESSION = ['tenant_id' => 0];
TenantContext::resetCache();
check('自托管 tenant0 → users 不过滤(getAll仍合法)', TenantContext::scopeClause('u') === '');
check('自托管 tenant0 → clicks 导出不过滤', TenantContext::scopeCondition('cl') === null);

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
