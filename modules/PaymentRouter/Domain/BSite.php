<?php
/**
 * BSite — B 站实体（收款站）
 *
 * 放置合规普货商品，绑定正规收款账户，实际完成收款。
 * 支持冷却机制：连续失败 N 次 → 自动标记 cooling → 冷却结束自动恢复。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Domain;

final class BSite
{
    public int $id;
    public int $tenantId;
    public string $domain;
    public string $paymentGateway;
    public int $weight;
    public int $maxDailyOrders;
    public string $status;
    public ?string $cooledUntil;
    public int $consecutiveFailures;
    public int $dailyOrderCount;

    public function __construct(
        int $id,
        int $tenantId,
        string $domain,
        string $paymentGateway = 'paypal',
        int $weight = 1,
        int $maxDailyOrders = 50,
        string $status = 'active',
        ?string $cooledUntil = null,
        int $consecutiveFailures = 0,
        int $dailyOrderCount = 0,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->domain = $domain;
        $this->paymentGateway = $paymentGateway;
        $this->weight = $weight;
        $this->maxDailyOrders = $maxDailyOrders;
        $this->status = $status;
        $this->cooledUntil = $cooledUntil;
        $this->consecutiveFailures = $consecutiveFailures;
        $this->dailyOrderCount = $dailyOrderCount;
    }

    /** 标记为冷却状态 */
    public function cool(int $cooldownMinutes = 30): self
    {
        $cooledUntil = date('Y-m-d H:i:s', time() + ($cooldownMinutes * 60));

        return new self(
            $this->id, $this->tenantId, $this->domain,
            $this->paymentGateway, $this->weight, $this->maxDailyOrders,
            'cooling', $cooledUntil, $this->consecutiveFailures, $this->dailyOrderCount
        );
    }

    /** 从冷却恢复 */
    public function recover(): self
    {
        return new self(
            $this->id, $this->tenantId, $this->domain,
            $this->paymentGateway, $this->weight, $this->maxDailyOrders,
            'active', null, 0, $this->dailyOrderCount
        );
    }

    /** 每日订单计数 +1 */
    public function incrementDailyOrders(): self
    {
        return new self(
            $this->id, $this->tenantId, $this->domain,
            $this->paymentGateway, $this->weight, $this->maxDailyOrders,
            $this->status, $this->cooledUntil, $this->consecutiveFailures,
            $this->dailyOrderCount + 1
        );
    }

    /** 检查是否超过日订单上限 */
    public function isAtDailyLimit(): bool
    {
        return $this->dailyOrderCount >= $this->maxDailyOrders;
    }

    /** 检查是否处于冷却期 */
    public function isInCooldown(): bool
    {
        if ($this->status !== 'cooling' || $this->cooledUntil === null) {
            return false;
        }
        return strtotime($this->cooledUntil) > time();
    }

    /** 检查是否可用（active 且不在冷却期且未达上限） */
    public function isAvailable(): bool
    {
        return $this->status === 'active'
            && !$this->isInCooldown()
            && !$this->isAtDailyLimit();
    }
}
