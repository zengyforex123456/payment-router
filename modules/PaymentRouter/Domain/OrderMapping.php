<?php
/**
 * OrderMapping — 订单映射实体（不可变）
 *
 * 记录 A 站订单号与 B 站订单号的映射关系。
 * 一旦写入不可修改（通过状态转换返回 new self）。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Domain;

final class OrderMapping
{
    public int $id;
    public int $tenantId;
    public string $aOrderId;
    public ?string $bOrderId;
    public int $aSiteId;
    public int $bSiteId;
    public string $amount;
    public string $currency;
    public string $status;
    public ?string $routingReason;
    public string $dispatchedAt;
    public ?string $paidAt;

    public function __construct(
        int $id,
        int $tenantId,
        string $aOrderId,
        ?string $bOrderId,
        int $aSiteId,
        int $bSiteId,
        string $amount,
        string $currency = 'USD',
        string $status = 'pending',
        ?string $routingReason = null,
        string $dispatchedAt = '',
        ?string $paidAt = null,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->aOrderId = $aOrderId;
        $this->bOrderId = $bOrderId;
        $this->aSiteId = $aSiteId;
        $this->bSiteId = $bSiteId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->status = $status;
        $this->routingReason = $routingReason;
        $this->dispatchedAt = $dispatchedAt !== '' ? $dispatchedAt : date('Y-m-d H:i:s');
        $this->paidAt = $paidAt;
    }

    public function markPaid(string $bOrderId): self
    {
        return new self(
            $this->id, $this->tenantId, $this->aOrderId, $bOrderId,
            $this->aSiteId, $this->bSiteId, $this->amount, $this->currency,
            'paid', $this->routingReason, $this->dispatchedAt, date('Y-m-d H:i:s')
        );
    }

    public function markFailed(): self
    {
        return new self(
            $this->id, $this->tenantId, $this->aOrderId, $this->bOrderId,
            $this->aSiteId, $this->bSiteId, $this->amount, $this->currency,
            'failed', $this->routingReason, $this->dispatchedAt, $this->paidAt
        );
    }

    public function markRefunded(): self
    {
        return new self(
            $this->id, $this->tenantId, $this->aOrderId, $this->bOrderId,
            $this->aSiteId, $this->bSiteId, $this->amount, $this->currency,
            'refunded', $this->routingReason, $this->dispatchedAt, $this->paidAt
        );
    }
}
