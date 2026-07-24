<?php
/**
 * SuperAdminDashboardUseCase — 多租户管理面板
 *
 * 企业版: 服务商/代理在一个面板查看所有终端客户的运行状态。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;

final class SuperAdminDashboardUseCase
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * 返回所有租户概览。
     *
     * @return array{tenants: array, summary: array}
     */
    public function execute(): array
    {
        // 所有租户的汇总数据
        $stmt = $this->db->prepare(
            'SELECT
                t.tenant_id,
                tc.tier,
                COUNT(DISTINCT a.id) AS a_site_count,
                COUNT(DISTINCT b.id) AS b_site_count,
                COUNT(DISTINCT CASE WHEN m.status = \'paid\' THEN m.id END) AS paid_today,
                COUNT(DISTINCT CASE WHEN m.status = \'failed\' THEN m.id END) AS failed_today,
                COALESCE(SUM(CASE WHEN m.status = \'paid\' THEN m.amount ELSE 0 END), 0) AS revenue_today
             FROM (SELECT DISTINCT tenant_id FROM payment_router_a_sites
                   UNION SELECT DISTINCT tenant_id FROM payment_router_b_sites) t
             LEFT JOIN payment_router_tenant_config tc ON t.tenant_id = tc.tenant_id
             LEFT JOIN payment_router_a_sites a ON t.tenant_id = a.tenant_id
             LEFT JOIN payment_router_b_sites b ON t.tenant_id = b.tenant_id
             LEFT JOIN payment_router_order_mappings m ON t.tenant_id = m.tenant_id
               AND DATE(m.dispatched_at) = CURDATE()
             GROUP BY t.tenant_id, tc.tier
             ORDER BY revenue_today DESC'
        );
        $stmt->execute();
        $tenants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // 汇总
        $total = [
            'tenants'       => count($tenants),
            'total_a_sites' => array_sum(array_column($tenants, 'a_site_count')),
            'total_b_sites' => array_sum(array_column($tenants, 'b_site_count')),
            'paid_today'    => array_sum(array_column($tenants, 'paid_today')),
            'revenue_today' => array_sum(array_column($tenants, 'revenue_today')),
        ];

        return ['tenants' => $tenants, 'summary' => $total];
    }
}
