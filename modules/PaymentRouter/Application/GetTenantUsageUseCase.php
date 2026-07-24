<?php
/**
 * GetTenantUsageUseCase — 租户用量与限额查询
 *
 * SaaS 仪表盘: 显示当前用量 / 套餐限额 / 功能可用性。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;
use Converge\Modules\PaymentRouter\Domain\TenantUsage;

final class GetTenantUsageUseCase
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function execute(int $tenantId): array
    {
        $usage = new TenantUsage();

        // 查询套餐等级
        $stmt = $this->db->prepare('SELECT tier FROM payment_router_tenant_config WHERE tenant_id = ?');
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $usage->tier = $row['tier'] ?? 'free';

        // 统计 A/B 站点数
        $stmt2 = $this->db->prepare('SELECT COUNT(*) as cnt FROM payment_router_a_sites WHERE tenant_id = ?');
        $stmt2->bind_param('i', $tenantId);
        $stmt2->execute();
        $usage->aSiteCount = (int)$stmt2->get_result()->fetch_assoc()['cnt'];

        $stmt3 = $this->db->prepare('SELECT COUNT(*) as cnt FROM payment_router_b_sites WHERE tenant_id = ?');
        $stmt3->bind_param('i', $tenantId);
        $stmt3->execute();
        $usage->bSiteCount = (int)$stmt3->get_result()->fetch_assoc()['cnt'];

        // 当月订单数
        $period = date('Y-m');
        $stmt4 = $this->db->prepare(
            'SELECT dispatch_count FROM payment_router_usage WHERE tenant_id = ? AND period = ?'
        );
        $stmt4->bind_param('is', $tenantId, $period);
        $stmt4->execute();
        $row4 = $stmt4->get_result()->fetch_assoc();
        $usage->monthlyOrderCount = $row4 ? (int)$row4['dispatch_count'] : 0;

        return $usage->limits();
    }
}
