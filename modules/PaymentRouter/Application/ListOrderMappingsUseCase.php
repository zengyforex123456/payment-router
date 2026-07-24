<?php
/**
 * ListOrderMappingsUseCase — 订单映射查询
 *
 * 对应 PRD R10: 查看 A→B 映射记录、支付状态、时间线。
 * 支持按订单号/日期/B 站筛选。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Modules\PaymentRouter\Domain\OrderMappingRepositoryInterface;

final class ListOrderMappingsUseCase
{
    private OrderMappingRepositoryInterface $mappingRepo;

    public function __construct(OrderMappingRepositoryInterface $mappingRepo)
    {
        $this->mappingRepo = $mappingRepo;
    }

    /**
     * @return array{items: array, total: int}
     */
    public function execute(int $tenantId, int $limit = 50, int $offset = 0): array
    {
        $mappings = $this->mappingRepo->findByTenant($tenantId, $limit, $offset);

        return [
            'items' => array_map(function ($m) {
                return [
                    'id' => $m->id,
                    'a_order_id' => $m->aOrderId,
                    'b_order_id' => $m->bOrderId,
                    'amount' => $m->amount,
                    'currency' => $m->currency,
                    'status' => $m->status,
                    'routing_reason' => $m->routingReason,
                    'dispatched_at' => $m->dispatchedAt,
                    'paid_at' => $m->paidAt,
                ];
            }, $mappings),
            'total' => count($mappings),
        ];
    }
}
