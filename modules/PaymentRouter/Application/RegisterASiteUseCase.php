<?php
/**
 * RegisterASiteUseCase — 注册 A 站
 *
 * 对应 PRD R1: A 站注册到中控，生成 API Key 用于后续通信签名。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Modules\PaymentRouter\Domain\ASite;
use Converge\Modules\PaymentRouter\Domain\ASiteRepositoryInterface;

final class RegisterASiteUseCase
{
    private ASiteRepositoryInterface $aSiteRepo;

    public function __construct(ASiteRepositoryInterface $aSiteRepo)
    {
        $this->aSiteRepo = $aSiteRepo;
    }

    public function execute(int $tenantId, string $domain, string $platform = 'woocommerce'): ASite
    {
        $site = new ASite(0, $tenantId, $domain, $platform);
        return $this->aSiteRepo->save($site);
    }
}
