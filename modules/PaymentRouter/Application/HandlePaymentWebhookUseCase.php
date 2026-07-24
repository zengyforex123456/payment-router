<?php
/**
 * HandlePaymentWebhookUseCase — 支付回调处理（核心 UseCase）
 *
 * 对应 PRD R6: 支付成功/失败 → B→中控→A 双向同步
 * 对应 PRD R8: B 站自动冷却——连续失败 N 次 → 标记 cooling → 冷却时间到自动恢复
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;
use Converge\Modules\PaymentRouter\Domain\BSiteRepositoryInterface;
use Converge\Modules\PaymentRouter\Domain\OrderMappingRepositoryInterface;
use RuntimeException;

final class HandlePaymentWebhookUseCase
{
    private OrderMappingRepositoryInterface $mappingRepo;
    private BSiteRepositoryInterface $bSiteRepo;
    private int $coolingThreshold;
    private ?DatabaseInterface $db;

    public function __construct(
        OrderMappingRepositoryInterface $mappingRepo,
        BSiteRepositoryInterface $bSiteRepo,
        ?DatabaseInterface $db = null,
        int $coolingThreshold = 3,
    ) {
        $this->mappingRepo = $mappingRepo;
        $this->bSiteRepo = $bSiteRepo;
        $this->db = $db;
        $this->coolingThreshold = $coolingThreshold;
    }

    /**
     * 处理 B 站支付结果回调
     *
     * @param array $input {b_order_id, status: paid|failed|refunded, transaction_id?}
     * @return array {acknowledged, mapping_status, b_site_status}
     */
    public function execute(array $input): array
    {
        $bOrderId = $input['b_order_id'] ?? '';
        $status = $input['status'] ?? 'failed';

        // 1. 查找订单映射
        $mapping = $this->mappingRepo->findByBOrderId($bOrderId);
        if ($mapping === null) {
            throw new RuntimeException("未找到 B 站订单映射: {$bOrderId}");
        }

        // 2. 更新映射状态
        $updatedMapping = match ($status) {
            'paid' => $mapping->markPaid($bOrderId),
            'failed' => $mapping->markFailed(),
            'refunded' => $mapping->markRefunded(),
            default => throw new RuntimeException("未知支付状态: {$status}"),
        };
        $this->mappingRepo->save($updatedMapping);

        // 3. 从 DB 读取租户策略的冷却阈值（若 DB 不可用则用默认值）
        $threshold = $this->coolingThreshold;
        if ($this->db !== null) {
            $tenantId = $mapping->tenantId;
            $stmt = $this->db->prepare('SELECT cooling_threshold FROM payment_router_tenant_config WHERE tenant_id = ?');
            $stmt->bind_param('i', $tenantId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && (int)$row['cooling_threshold'] > 0) {
                $threshold = (int)$row['cooling_threshold'];
            }
        }

        // 4. 更新 B 站状态（自动冷却/恢复）
        $bSite = $this->bSiteRepo->findById($mapping->bSiteId);
        $bSiteStatus = 'unchanged';

        if ($bSite !== null) {
            if ($status === 'failed') {
                $updatedBSite = new \Converge\Modules\PaymentRouter\Domain\BSite(
                    $bSite->id, $bSite->tenantId, $bSite->domain,
                    $bSite->paymentGateway, $bSite->weight, $bSite->maxDailyOrders,
                    $bSite->status, $bSite->cooledUntil,
                    $bSite->consecutiveFailures + 1,
                    $bSite->dailyOrderCount
                );

                if ($updatedBSite->consecutiveFailures >= $threshold) {
                    $updatedBSite = $updatedBSite->cool();
                    $bSiteStatus = 'cooled';
                }
                $this->bSiteRepo->save($updatedBSite);
            } elseif ($status === 'paid') {
                // 支付成功 → 重置失败计数
                $updatedBSite = $bSite->recover();
                $this->bSiteRepo->save($updatedBSite);
                $bSiteStatus = 'recovered';
            }
        }

        return [
            'acknowledged' => true,
            'mapping_status' => $updatedMapping->status,
            'b_site_status' => $bSiteStatus,
        ];
    }
}
