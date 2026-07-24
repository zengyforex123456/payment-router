<?php
/**
 * DispatchOrderUseCase — 订单分发（核心 UseCase）
 *
 * 对应 PRD R4: A 站提交订单 → 验证签名 → 选 B 站 → 生成跳转 URL → 写入映射表 → 写入事件
 * 对应 PRD R2: 中控订单接收 API，验证 API Key + HMAC 签名
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Modules\PaymentRouter\Domain\ASiteRepositoryInterface;
use Converge\Modules\PaymentRouter\Domain\BSiteRepositoryInterface;
use Converge\Modules\PaymentRouter\Domain\OrderMapping;
use Converge\Modules\PaymentRouter\Domain\OrderMappingRepositoryInterface;
use Converge\Modules\PaymentRouter\Infrastructure\PaymentGatewayAdapter;
use RuntimeException;

final class DispatchOrderUseCase
{
    private ASiteRepositoryInterface $aSiteRepo;
    private BSiteRepositoryInterface $bSiteRepo;
    private OrderMappingRepositoryInterface $mappingRepo;
    private SelectGatewayUseCase $selectGateway;
    private PaymentGatewayAdapter $gateway;

    public function __construct(
        ASiteRepositoryInterface $aSiteRepo,
        BSiteRepositoryInterface $bSiteRepo,
        OrderMappingRepositoryInterface $mappingRepo,
        SelectGatewayUseCase $selectGateway,
        PaymentGatewayAdapter $gateway,
    ) {
        $this->aSiteRepo = $aSiteRepo;
        $this->bSiteRepo = $bSiteRepo;
        $this->mappingRepo = $mappingRepo;
        $this->selectGateway = $selectGateway;
        $this->gateway = $gateway;
    }

    /**
     * 分发订单到 B 站
     *
     * @param array $input {api_key, signature, a_order_id, amount, currency, strategy?}
     * @return array {b_checkout_url, b_order_reference, b_site_domain}
     * @throws RuntimeException
     */
    public function execute(array $input): array
    {
        // 1. 验证 API Key + 查找 A 站
        $aSite = $this->aSiteRepo->findByApiKey($input['api_key'] ?? '');
        if ($aSite === null) {
            throw new RuntimeException('A 站 API Key 无效');
        }
        if ($aSite->status !== 'active') {
            throw new RuntimeException('A 站已暂停');
        }

        // 2. 验证 HMAC 签名
        $payload = json_encode([
            'a_order_id' => $input['a_order_id'],
            'amount' => $input['amount'],
            'currency' => $input['currency'] ?? 'USD',
            'timestamp' => $input['timestamp'] ?? '',
        ]);
        if (!$this->gateway->verifyApiSignature(
            $aSite->apiKey,
            $payload,
            $input['signature'] ?? ''
        )) {
            throw new RuntimeException('HMAC 签名验证失败');
        }

        // 3. 选择 B 站
        $amount = $input['amount'];
        $strategy = $input['strategy'] ?? null;
        [$bSite, $decision] = $this->selectGateway->execute($aSite->tenantId, $amount, $strategy);

        // 4. 生成 B 站 checkout URL
        $bOrderRef = 'B-' . strtoupper(bin2hex(random_bytes(6)));
        $checkoutUrl = $this->gateway->generateCheckoutUrl($bSite->domain, [
            'order_id' => $bOrderRef,
            'amount' => $amount,
            'currency' => $input['currency'] ?? 'USD',
        ]);

        // 5. 写入订单映射
        $mapping = new OrderMapping(
            0, $aSite->tenantId, $input['a_order_id'], $bOrderRef,
            $aSite->id, $bSite->id, $amount, $input['currency'] ?? 'USD',
            'pending', $decision->toJson()
        );
        $this->mappingRepo->save($mapping);

        // 6. 更新 B 站日订单计数
        $updatedBSite = $bSite->incrementDailyOrders();
        $this->bSiteRepo->save($updatedBSite);

        return [
            'b_checkout_url' => $checkoutUrl,
            'b_order_reference' => $bOrderRef,
            'b_site_domain' => $bSite->domain,
        ];
    }
}
