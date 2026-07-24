<?php
/**
 * TenantUsage — 租户用量统计值对象
 *
 * SaaS 入门版: 限制 A 站数 / B 站数 / 月订单数。
 * 专业版/企业版: 更高或无限。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Domain;

final class TenantUsage
{
    /** 套餐限制 */
    public const TIERS = [
        'free' => [
            'max_a_sites'       => 1,
            'max_b_sites'       => 2,
            'max_monthly_orders'=> 1000,
            'features'          => ['weighted', 'round_robin'],
        ],
        'starter' => [  // $86/月
            'max_a_sites'       => 2,
            'max_b_sites'       => 5,
            'max_monthly_orders'=> 10000,
            'features'          => ['weighted', 'round_robin', 'amount_threshold', 'random', 'dashboard'],
        ],
        'pro' => [      // $600-700 一次性
            'max_a_sites'       => 5,
            'max_b_sites'       => 10,
            'max_monthly_orders'=> 100000,
            'features'          => ['*'],
        ],
        'enterprise' => [ // $2000+
            'max_a_sites'       => PHP_INT_MAX,
            'max_b_sites'       => PHP_INT_MAX,
            'max_monthly_orders'=> PHP_INT_MAX,
            'features'          => ['*'],
        ],
    ];

    public int $aSiteCount = 0;
    public int $bSiteCount = 0;
    public int $monthlyOrderCount = 0;
    public string $tier = 'free';

    public function canAddASite(): bool
    {
        $limit = self::TIERS[$this->tier]['max_a_sites'] ?? 1;
        return $this->aSiteCount < $limit;
    }

    public function canAddBSite(): bool
    {
        $limit = self::TIERS[$this->tier]['max_b_sites'] ?? 2;
        return $this->bSiteCount < $limit;
    }

    public function canDispatch(): bool
    {
        $limit = self::TIERS[$this->tier]['max_monthly_orders'] ?? 1000;
        return $this->monthlyOrderCount < $limit;
    }

    public function hasFeature(string $feature): bool
    {
        $features = self::TIERS[$this->tier]['features'] ?? [];
        return in_array('*', $features, true) || in_array($feature, $features, true);
    }

    public function limits(): array
    {
        $t = self::TIERS[$this->tier] ?? self::TIERS['free'];
        return [
            'tier'               => $this->tier,
            'max_a_sites'        => $t['max_a_sites'],
            'max_b_sites'        => $t['max_b_sites'],
            'max_monthly_orders' => $t['max_monthly_orders'],
            'a_sites_used'       => $this->aSiteCount,
            'b_sites_used'       => $this->bSiteCount,
            'orders_used'        => $this->monthlyOrderCount,
            'features'           => $t['features'],
        ];
    }
}
