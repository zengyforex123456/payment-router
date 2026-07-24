<?php
/**
 * CloakVisitor — 访客信息值对象
 *
 * 从 HTTP 请求中提取所有可用于身份判定的信号。
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Cloak\Domain;

final class CloakVisitor
{
    public string $ip;
    public string $userAgent;
    public string $acceptLanguage;
    public string $referrer;
    public string $country;    // ISO 3166-1 alpha-2
    public bool $isProxy;
    public bool $isDatacenter;
    public array $matches;      // 命中的规则列表

    public function __construct(
        string $ip, string $userAgent = '', string $acceptLanguage = '',
        string $referrer = '', string $country = 'XX',
        bool $isProxy = false, bool $isDatacenter = false, array $matches = [],
    ) {
        $this->ip = $ip; $this->userAgent = $userAgent;
        $this->acceptLanguage = $acceptLanguage; $this->referrer = $referrer;
        $this->country = $country; $this->isProxy = $isProxy;
        $this->isDatacenter = $isDatacenter; $this->matches = $matches;
    }

    /** 从 $_SERVER 创建 */
    public static function fromServer(array $server): self
    {
        return new self(
            ip:     $server['REMOTE_ADDR'] ?? '0.0.0.0',
            userAgent:    $server['HTTP_USER_AGENT'] ?? '',
            acceptLanguage: $server['HTTP_ACCEPT_LANGUAGE'] ?? '',
            referrer:     $server['HTTP_REFERER'] ?? '',
        );
    }
}
