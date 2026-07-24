<?php
/**
 * OemConfig — OEM 白标配置值对象
 *
 * 企业版: 服务商/代理可去除品牌标识，替换为自己的品牌。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Domain;

final class OemConfig
{
    public string $appName;
    public string $logoUrl;
    public string $primaryColor;
    public string $supportEmail;
    public string $footerText;

    private const DEFAULTS = [
        'app_name'      => 'PaymentRouter',
        'logo_url'      => '',
        'primary_color' => '#3b82f6',
        'support_email' => '',
        'footer_text'   => 'Powered by PaymentRouter',
    ];

    public function __construct(array $config = [])
    {
        $this->appName      = $config['app_name']      ?? self::DEFAULTS['app_name'];
        $this->logoUrl      = $config['logo_url']      ?? self::DEFAULTS['logo_url'];
        $this->primaryColor = $config['primary_color'] ?? self::DEFAULTS['primary_color'];
        $this->supportEmail = $config['support_email'] ?? self::DEFAULTS['support_email'];
        $this->footerText   = $config['footer_text']   ?? self::DEFAULTS['footer_text'];
    }

    public function toArray(): array
    {
        return [
            'app_name'      => $this->appName,
            'logo_url'      => $this->logoUrl,
            'primary_color' => $this->primaryColor,
            'support_email' => $this->supportEmail,
            'footer_text'   => $this->footerText,
        ];
    }

    /** 检查是否已自定义（非默认值） */
    public function isCustomized(): bool
    {
        return $this->appName !== self::DEFAULTS['app_name']
            || $this->logoUrl !== self::DEFAULTS['logo_url']
            || $this->primaryColor !== self::DEFAULTS['primary_color'];
    }

    public static function defaults(): array
    {
        return self::DEFAULTS;
    }
}
