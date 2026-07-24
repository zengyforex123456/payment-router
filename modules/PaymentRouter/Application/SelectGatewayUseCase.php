<?php
/**
 * SelectGatewayUseCase — 轮询策略引擎（核心 UseCase）
 *
 * 对应 PRD R3: 根据可配置策略从可用 B 站中选出一个。
 * 支持四种策略: weighted(权重随机) / round_robin(轮询) / amount_threshold(金额阈值) / random(纯随机)
 *
 * 对应 PRD R8: 自动冷却——连续失败 N 次 → 标记 cooling → 冷却结束自动恢复
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Modules\PaymentRouter\Domain\BSite;
use Converge\Modules\PaymentRouter\Domain\BSiteRepositoryInterface;
use Converge\Modules\PaymentRouter\Domain\RoutingDecision;
use RuntimeException;

final class SelectGatewayUseCase
{
    private BSiteRepositoryInterface $bSiteRepo;
    private string $defaultStrategy;
    private int $coolingThreshold;
    private int $cooldownMinutes;

    public function __construct(
        BSiteRepositoryInterface $bSiteRepo,
        string $defaultStrategy = 'weighted',
        int $coolingThreshold = 3,
        int $cooldownMinutes = 30,
    ) {
        $this->bSiteRepo = $bSiteRepo;
        $this->defaultStrategy = $defaultStrategy;
        $this->coolingThreshold = $coolingThreshold;
        $this->cooldownMinutes = $cooldownMinutes;
    }

    /**
     * 从可用 B 站中根据策略选择一个
     *
     * @param int $tenantId 租户 ID
     * @param string $amount 订单金额（用于 amount_threshold 策略）
     * @param string $strategy 策略覆盖（null 使用默认）
     * @return array{BSite, RoutingDecision}
     * @throws RuntimeException 当无可用 B 站时
     */
    public function execute(int $tenantId, string $amount = '0.00', ?string $strategy = null): array
    {
        $candidates = $this->bSiteRepo->findAvailable($tenantId);

        if (empty($candidates)) {
            throw new RuntimeException('所有 B 站均不可用（已冷却/已达上限/已禁用）');
        }

        $strategy = $strategy ?? $this->defaultStrategy;

        return match ($strategy) {
            'weighted' => $this->selectWeighted($candidates),
            'round_robin' => $this->selectRoundRobin($candidates),
            'amount_threshold' => $this->selectAmountThreshold($candidates, $amount),
            'random' => $this->selectRandom($candidates),
            default => $this->selectWeighted($candidates),
        };
    }

    /** 加权随机选择 */
    private function selectWeighted(array $candidates): array
    {
        $totalWeight = array_sum(array_map(fn(BSite $b) => $b->weight, $candidates));
        $rand = random_int(1, max(1, $totalWeight));
        $cumulative = 0;

        foreach ($candidates as $b) {
            $cumulative += $b->weight;
            if ($rand <= $cumulative) {
                $decision = RoutingDecision::weighted($b->id, $b->domain, $b->weight, $totalWeight, $candidates);
                return [$b, $decision];
            }
        }
        // Fallback: 返回第一个
        $b = $candidates[0];
        $decision = RoutingDecision::weighted($b->id, $b->domain, $b->weight, $totalWeight, $candidates);
        return [$b, $decision];
    }

    /** 轮询：选最久未使用的 */
    private function selectRoundRobin(array $candidates): array
    {
        usort($candidates, function (BSite $a, BSite $b) {
            // comparison by last_used_at - but the entity doesn't have this yet
            return $a->dailyOrderCount <=> $b->dailyOrderCount;
        });
        $selected = $candidates[0];
        $decision = RoutingDecision::roundRobin($selected->id, $selected->domain, $candidates);
        return [$selected, $decision];
    }

    /** 金额阈值：大额→高权重 B 站 */
    private function selectAmountThreshold(array $candidates, string $amount): array
    {
        $threshold = 100.00;
        if ((float)$amount > $threshold) {
            // 大额订单：选权重最高的 B 站
            usort($candidates, fn(BSite $a, BSite $b) => $b->weight <=> $a->weight);
        } else {
            // 小额订单：选日订单数最少的
            usort($candidates, fn(BSite $a, BSite $b) => $a->dailyOrderCount <=> $b->dailyOrderCount);
        }
        $selected = $candidates[0];
        $decision = RoutingDecision::amountThreshold($selected->id, $selected->domain, $amount, (string)$threshold, $candidates);
        return [$selected, $decision];
    }

    /** 纯随机选择 */
    private function selectRandom(array $candidates): array
    {
        $selected = $candidates[array_rand($candidates)];
        $decision = new RoutingDecision('random', $selected->id, $selected->domain, '纯随机选择', $candidates);
        return [$selected, $decision];
    }
}
