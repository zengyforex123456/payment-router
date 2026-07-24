<?php
/**
 * Admin_Settings — 插件设置页面
 *
 * 在 WooCommerce → 设置 → 支付 标签下注册配置项:
 *   - 中控 URL
 *   - API Key (自动生成)
 *   - Webhook 端点 (只读展示)
 */
declare(strict_types=1);

namespace ABPR;

final class Admin_Settings
{
    public static function init(): void
    {
        $instance = new self();
        add_filter('woocommerce_get_settings_pages', [$instance, 'addSettingsTab']);
    }

    public function addSettingsTab(array $pages): array
    {
        $pages[] = include ABPR_PLUGIN_DIR . 'includes/settings-page.php';
        return $pages;
    }
}
