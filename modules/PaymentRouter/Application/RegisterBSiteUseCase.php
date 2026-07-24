<?php
/**
 * RegisterBSiteUseCase — 注册 B 站
 *
 * 对应 PRD R2: B 站注册到中控，配置支付网关类型、权重、日订单上限。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Modules\PaymentRouter\Domain\BSite;
use Converge\Modules\PaymentRouter\Domain\BSiteRepositoryInterface;

final class RegisterBSiteUseCase
{
    private BSiteRepositoryInterface $bSiteRepo;

    public function __construct(BSiteRepositoryInterface $bSiteRepo)
    {
        $this->bSiteRepo = $bSiteRepo;
    }

    public function execute(
        int $tenantId,
        string $domain,
        string $paymentGateway = 'paypal',
        int $weight = 1,
        int $maxDailyOrders = 50,
    ): BSite {
        $site = new BSite(0, $tenantId, $domain, $paymentGateway, $weight, $maxDailyOrders);
        return $this->bSiteRepo->save($site);
    }
}
