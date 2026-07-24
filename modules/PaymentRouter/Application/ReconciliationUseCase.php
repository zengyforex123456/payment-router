<?php
/**
 * ReconciliationUseCase — 每日数据对账
 *
 * P2: 当支付成功但 Webhook 回调丢失时，自动检测差异并重试。
 * 建议通过 Cron 每天凌晨 2:00 调用。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;

final class ReconciliationUseCase
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * 执行对账：找出状态不一致的订单映射并尝试修复。
     *
     * @return array{checked: int, mismatches: int, fixed: int, details: array}
     */
    public function execute(int $tenantId): array
    {
        $mismatches = [];
        $fixed = 0;

        // 1. 查找长时间 pending 的映射（超过 1 小时仍未回调）
        $stmt = $this->db->prepare(
            "SELECT id, a_order_id, b_order_id, b_site_id, dispatched_at
             FROM payment_router_order_mappings
             WHERE tenant_id = ? AND status = 'pending'
               AND dispatched_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
             ORDER BY dispatched_at"
        );
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $staleOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $checked = count($staleOrders);

        foreach ($staleOrders as $order) {
            $mappingId = (int)$order['id'];
            $bOrderId = $order['b_order_id'];
            $bSiteId = (int)$order['b_site_id'];

            // 查询 B 站，尝试获取实际支付状态
            $bSite = $this->getBSiteDomain($bSiteId);
            $actualStatus = $bSite ? $this->queryBSiteOrderStatus($bSite, $bOrderId) : null;

            if ($actualStatus === 'paid' || $actualStatus === 'completed') {
                // 修复：标记为 paid
                $stmt2 = $this->db->prepare(
                    "UPDATE payment_router_order_mappings SET status = 'paid', paid_at = NOW() WHERE id = ?"
                );
                $stmt2->bind_param('i', $mappingId);
                $stmt2->execute();

                $mismatches[] = [
                    'mapping_id' => $mappingId,
                    'a_order_id' => $order['a_order_id'],
                    'b_order_id' => $bOrderId,
                    'issue'      => 'stale_pending_fixed',
                    'action'     => 'marked_paid',
                ];
                $fixed++;
            } elseif ($actualStatus === 'failed' || $actualStatus === 'cancelled') {
                $stmt2 = $this->db->prepare(
                    "UPDATE payment_router_order_mappings SET status = 'failed' WHERE id = ?"
                );
                $stmt2->bind_param('i', $mappingId);
                $stmt2->execute();

                $mismatches[] = [
                    'mapping_id' => $mappingId,
                    'a_order_id' => $order['a_order_id'],
                    'b_order_id' => $bOrderId,
                    'issue'      => 'stale_pending_fixed',
                    'action'     => 'marked_failed',
                ];
                $fixed++;
            } else {
                $mismatches[] = [
                    'mapping_id' => $mappingId,
                    'a_order_id' => $order['a_order_id'],
                    'b_order_id' => $bOrderId,
                    'issue'      => 'stale_pending_unresolved',
                    'action'     => 'needs_manual_review',
                ];
            }
        }

        // 2. 检查 paid 映射是否有对应的事件记录
        $stmt3 = $this->db->prepare(
            "SELECT m.id, m.a_order_id, m.b_order_id
             FROM payment_router_order_mappings m
             LEFT JOIN payment_router_events e ON m.b_order_id = e.aggregate_id AND e.event_type = 'payment_succeeded'
             WHERE m.tenant_id = ? AND m.status = 'paid' AND e.id IS NULL
             LIMIT 50"
        );
        $stmt3->bind_param('i', $tenantId);
        $stmt3->execute();
        $missingEvents = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($missingEvents as $me) {
            // 补写缺失的事件记录
            $stmt4 = $this->db->prepare(
                "INSERT INTO payment_router_events (tenant_id, event_type, aggregate_id, payload, created_at)
                 VALUES (?, 'payment_succeeded', ?, ?, NOW())"
            );
            $payload = json_encode([
                'b_order_id' => $me['b_order_id'],
                'a_order_id' => $me['a_order_id'],
                'source'     => 'reconciliation',
            ]);
            $stmt4->bind_param('iss', $tenantId, $me['b_order_id'], $payload);
            $stmt4->execute();

            $mismatches[] = [
                'mapping_id' => (int)$me['id'],
                'issue'      => 'missing_event',
                'action'     => 'event_backfilled',
            ];
        }

        $totalChecked = $checked + count($missingEvents);

        return compact('checked', 'mismatches', 'fixed');
    }

    private function getBSiteDomain(int $bSiteId): ?string
    {
        $stmt = $this->db->prepare('SELECT domain FROM payment_router_b_sites WHERE id = ?');
        $stmt->bind_param('i', $bSiteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? $row['domain'] : null;
    }

    private function queryBSiteOrderStatus(string $domain, string $bOrderId): ?string
    {
        $url = "https://{$domain}/index.php?route=api/order/status&order_ref=" . urlencode($bOrderId);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        if (!$resp) return null;
        $data = json_decode($resp, true);
        return $data['status'] ?? null;
    }
}
