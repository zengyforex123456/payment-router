<?php
/**
 * GetRoutingDashboardUseCase — 仪表盘数据聚合
 *
 * 对应 PRD R11: 各 B 站订单量/成功率/金额/冷却次数可视化。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;

final class GetRoutingDashboardUseCase
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /** 获取 7 天趋势数据（用于 Chart.js） */
    public function getTrends(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(dispatched_at) as dt,
                    COUNT(*) as total,
                    SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                    COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) as revenue
             FROM payment_router_order_mappings
             WHERE tenant_id = ? AND dispatched_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY dt ORDER BY dt"
        );
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'labels'   => array_column($rows, 'dt'),
            'orders'   => array_map('intval', array_column($rows, 'total')),
            'paid'     => array_map('intval', array_column($rows, 'paid')),
            'failed'   => array_map('intval', array_column($rows, 'failed')),
            'revenue'  => array_map('floatval', array_column($rows, 'revenue')),
        ];
    }

    /**
     * @return array{summary: array, b_sites: array}
     */
    public function execute(int $tenantId): array
    {
        // 汇总指标
        $stmt = $this->db->prepare(
            'SELECT
                COUNT(*) AS total_orders,
                SUM(CASE WHEN status = \'paid\' THEN 1 ELSE 0 END) AS paid_orders,
                SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) AS failed_orders,
                SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending_orders,
                COALESCE(SUM(CASE WHEN status = \'paid\' THEN amount ELSE 0 END), 0) AS total_revenue
             FROM payment_router_order_mappings
             WHERE tenant_id = ?'
        );
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();

        $paidCount = (int)($summary['paid_orders'] ?? 0);
        $totalCount = (int)($summary['total_orders'] ?? 0);
        $successRate = $totalCount > 0 ? round(($paidCount / $totalCount) * 100, 1) : 0;

        // 各 B 站明细
        $stmt2 = $this->db->prepare(
            'SELECT
                b.id, b.domain, b.payment_gateway, b.status AS b_status,
                b.weight, b.daily_order_count, b.consecutive_failures,
                COALESCE(COUNT(m.id), 0) AS total_mapped,
                COALESCE(SUM(CASE WHEN m.status = \'paid\' THEN 1 ELSE 0 END), 0) AS success_count,
                COALESCE(SUM(CASE WHEN m.status = \'failed\' THEN 1 ELSE 0 END), 0) AS fail_count
             FROM payment_router_b_sites b
             LEFT JOIN payment_router_order_mappings m
               ON b.id = m.b_site_id AND m.dispatched_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             WHERE b.tenant_id = ?
             GROUP BY b.id
             ORDER BY b.id'
        );
        $stmt2->bind_param('i', $tenantId);
        $stmt2->execute();
        $bSites = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'summary' => [
                'total_orders' => (int)($summary['total_orders'] ?? 0),
                'paid_orders' => $paidCount,
                'failed_orders' => (int)($summary['failed_orders'] ?? 0),
                'pending_orders' => (int)($summary['pending_orders'] ?? 0),
                'total_revenue' => (float)($summary['total_revenue'] ?? 0),
                'success_rate' => $successRate,
            ],
            'b_sites' => $bSites,
        ];
    }
}
