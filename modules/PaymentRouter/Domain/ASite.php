<?php
/**
 * ASite — A 站实体（展示站）
 *
 * 承接广告流量、展示商品、接收订单，不直接处理支付。
 * 状态转换返回 new self()，不可变模式。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Domain;

final class ASite
{
    public int $id;
    public int $tenantId;
    public string $domain;
    public string $platform;
    public string $apiKey;
    public string $status;

    public function __construct(
        int $id,
        int $tenantId,
        string $domain,
        string $platform = 'woocommerce',
        string $apiKey = '',
        string $status = 'active',
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->domain = $domain;
        $this->platform = $platform;
        $this->apiKey = $apiKey !== '' ? $apiKey : 'ck_' . bin2hex(random_bytes(24));
        $this->status = $status;
    }

    public function pause(): self
    {
        return new self(
            $this->id, $this->tenantId, $this->domain,
            $this->platform, $this->apiKey, 'paused'
        );
    }

    public function activate(): self
    {
        return new self(
            $this->id, $this->tenantId, $this->domain,
            $this->platform, $this->apiKey, 'active'
        );
    }
}
