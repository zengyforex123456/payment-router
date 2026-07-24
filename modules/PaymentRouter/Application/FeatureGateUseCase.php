<?php
/**
 * FeatureGateUseCase — 功能门禁
 *
 * 根据租户套餐限制：社区版(community)、入门版(starter)、专业版(pro)、企业版(enterprise)。
 * 未解锁功能返回明确的"升级提示"。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;
use Converge\Modules\PaymentRouter\Domain\TenantUsage;

final class FeatureGateUseCase
{
    private DatabaseInterface $db;

    /** 社区版限制 */
    public const COMMUNITY_LIMITS = [
        'max_a_sites'       => 1,
        'max_b_sites'       => 1,
        'strategies'        => ['weighted'],
        'dashboard'         => false,
        'alerts'            => false,
        'export'            => false,
        'bulk_import'       => false,
        'routing_script'    => false,
        'oem'               => false,
        'multi_tenant'      => false,
    ];

    /** 各功能升级提示文案 */
    public const UPGRADE_MESSAGES = [
        'max_b_sites'    => '社区版仅支持 1 个 B 站。升级到入门版解锁 2 个 B 站。',
        'max_a_sites'    => '社区版仅支持 1 个 A 站。升级到入门版解锁 2 个 A 站。',
        'strategies'     => '社区版仅支持均衡轮询策略。升级到入门版解锁全部 4 种策略。',
        'dashboard'      => '仪表盘功能需要入门版及以上。',
        'alerts'         => '告警通知功能需要入门版及以上。',
        'export'         => '配置导出功能需要专业版及以上。',
        'bulk_import'    => '批量导入功能需要企业版。',
        'routing_script' => 'DSL 路由脚本需要企业版。',
        'oem'            => 'OEM 白标功能需要企业版。',
        'multi_tenant'   => '多租户管理需要企业版。',
    ];

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /** 获取租户的完整功能权限表 */
    public function getPermissions(int $tenantId): array
    {
        $tier = $this->getTier($tenantId);

        $permissions = [
            'tier'               => $tier,
            'can_add_a_site'     => true,
            'can_add_b_site'     => true,
            'max_a_sites'        => PHP_INT_MAX,
            'max_b_sites'        => PHP_INT_MAX,
            'max_monthly_orders' => PHP_INT_MAX,
            'strategies'         => ['weighted', 'round_robin', 'amount_threshold', 'random'],
            'dashboard'          => true,
            'alerts'             => true,
            'export'             => true,
            'bulk_import'        => true,
            'routing_script'     => true,
            'oem'                => true,
            'multi_tenant'       => true,
        ];

        return match ($tier) {
            'community'  => array_merge($permissions, self::COMMUNITY_LIMITS),
            'starter'    => $permissions,
            'pro'        => $permissions,
            'enterprise' => $permissions,
            default      => array_merge($permissions, self::COMMUNITY_LIMITS),
        };
    }

    /** 检查是否可以创建 B 站 */
    public function canAddBSite(int $tenantId): array
    {
        $perm = $this->getPermissions($tenantId);
        $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM payment_router_b_sites WHERE tenant_id = ?');
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];

        if ($count >= $perm['max_b_sites']) {
            return [
                'allowed' => false,
                'reason'  => 'limit_reached',
                'current' => $count,
                'max'     => $perm['max_b_sites'],
                'message' => self::UPGRADE_MESSAGES['max_b_sites'],
            ];
        }
        return ['allowed' => true];
    }

    /** 检查功能是否可用 */
    public function check(int $tenantId, string $feature): array
    {
        $perm = $this->getPermissions($tenantId);

        if (is_bool($perm[$feature] ?? null)) {
            if (!$perm[$feature]) {
                return [
                    'allowed' => false,
                    'message' => self::UPGRADE_MESSAGES[$feature] ?? "功能 '{$feature}' 需要升级套餐。",
                ];
            }
        }

        return ['allowed' => true];
    }

    private function getTier(int $tenantId): string
    {
        $stmt = $this->db->prepare('SELECT tier FROM payment_router_tenant_config WHERE tenant_id = ?');
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row['tier'] ?? 'community';
    }
}
