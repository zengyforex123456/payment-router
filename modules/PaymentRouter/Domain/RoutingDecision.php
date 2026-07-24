<?php
/**
 * RoutingDecision — 路由决策值对象
 *
 * 记录"为什么选择了这个 B 站"，用于审计和策略优化。
 * 写入 OrderMapping.routing_reason 和 EventStore。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Domain;

final class RoutingDecision
{
    public string $strategy;
    public int $bSiteId;
    public string $bSiteDomain;
    public string $reason;
    public array $candidates;

    public function __construct(
        string $strategy,
        int $bSiteId,
        string $bSiteDomain,
        string $reason,
        array $candidates = [],
    ) {
        $this->strategy = $strategy;
        $this->bSiteId = $bSiteId;
        $this->bSiteDomain = $bSiteDomain;
        $this->reason = $reason;
        $this->candidates = $candidates;
    }

    public function toJson(): string
    {
        return json_encode([
            'strategy' => $this->strategy,
            'b_site_id' => $this->bSiteId,
            'b_site_domain' => $this->bSiteDomain,
            'reason' => $this->reason,
            'candidates_count' => count($this->candidates),
            'timestamp' => date('c'),
        ], JSON_UNESCAPED_UNICODE);
    }

    /** 工厂方法：加权随机策略 */
    public static function weighted(
        int $bSiteId,
        string $bSiteDomain,
        int $weight,
        int $totalWeight,
        array $candidates,
    ): self {
        return new self(
            'weighted',
            $bSiteId,
            $bSiteDomain,
            "权重 {$weight}/{$totalWeight} 随机选中",
            $candidates
        );
    }

    /** 工厂方法：轮询策略 */
    public static function roundRobin(
        int $bSiteId,
        string $bSiteDomain,
        array $candidates,
    ): self {
        return new self(
            'round_robin',
            $bSiteId,
            $bSiteDomain,
            '按最后使用时间轮询选中',
            $candidates
        );
    }

    /** 工厂方法：金额阈值策略 */
    public static function amountThreshold(
        int $bSiteId,
        string $bSiteDomain,
        string $amount,
        string $threshold,
        array $candidates,
    ): self {
        return new self(
            'amount_threshold',
            $bSiteId,
            $bSiteDomain,
            "金额 \${$amount} " . ((float)$amount > (float)$threshold ? '>' : '<=') . " 阈值 \${$threshold}",
            $candidates
        );
    }
}
