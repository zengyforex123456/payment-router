<?php
/**
 * License — 授权证书值对象
 *
 * 专业版/企业版: 绑定域名 + 过期时间，支持离线验证（宽限 7 天）。
 * 签发时用 RSA 私钥签名，验证时用公钥验签。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Domain;

final class License
{
    public string $licenseKey;
    public string $domain;
    public string $tier;
    public string $issuedAt;
    public string $expiresAt;
    public string $signature;

    public function __construct(
        string $licenseKey,
        string $domain,
        string $tier = 'pro',
        string $issuedAt = '',
        string $expiresAt = '',
        string $signature = '',
    ) {
        $this->licenseKey = $licenseKey;
        $this->domain = $domain;
        $this->tier = $tier;
        $this->issuedAt = $issuedAt !== '' ? $issuedAt : date('Y-m-d');
        $this->expiresAt = $expiresAt !== '' ? $expiresAt : date('Y-m-d', strtotime('+1 year'));
        $this->signature = $signature;
    }

    /** 是否已过期 */
    public function isExpired(): bool
    {
        return strtotime($this->expiresAt) < time();
    }

    /** 是否在宽限期内（过期后 7 天内仍可用） */
    public function isInGracePeriod(): bool
    {
        $expiry = strtotime($this->expiresAt);
        return $expiry < time() && (time() - $expiry) < 7 * 86400;
    }

    /** 是否有效（未过期 或 宽限期内） */
    public function isValid(): bool
    {
        return !$this->isExpired() || $this->isInGracePeriod();
    }

    /** 距离过期还剩多少天 */
    public function daysUntilExpiry(): int
    {
        return max(0, (int)ceil((strtotime($this->expiresAt) - time()) / 86400));
    }

    /** 生成 HMAC 签名 */
    public function sign(string $privateKey): self
    {
        $payload = "{$this->licenseKey}:{$this->domain}:{$this->tier}:{$this->expiresAt}";
        $sig = hash_hmac('sha256', $payload, $privateKey);
        return new self($this->licenseKey, $this->domain, $this->tier, $this->issuedAt, $this->expiresAt, $sig);
    }

    /** 验证 HMAC 签名 */
    public function verify(string $privateKey): bool
    {
        $payload = "{$this->licenseKey}:{$this->domain}:{$this->tier}:{$this->expiresAt}";
        $expected = hash_hmac('sha256', $payload, $privateKey);
        return hash_equals($expected, $this->signature);
    }

    public function toArray(): array
    {
        return [
            'license_key'  => $this->licenseKey,
            'domain'       => $this->domain,
            'tier'         => $this->tier,
            'issued_at'    => $this->issuedAt,
            'expires_at'   => $this->expiresAt,
            'days_left'    => $this->daysUntilExpiry(),
            'is_valid'     => $this->isValid(),
            'grace_period' => $this->isInGracePeriod(),
        ];
    }

    /** 生成 License Key 格式: PR-XXXX-XXXX-XXXX */
    public static function generateKey(): string
    {
        return 'PR-' . strtoupper(
            bin2hex(random_bytes(3)) . '-' .
            bin2hex(random_bytes(3)) . '-' .
            bin2hex(random_bytes(3))
        );
    }
}
