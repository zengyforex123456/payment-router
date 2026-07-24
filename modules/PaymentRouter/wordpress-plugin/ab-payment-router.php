<?php
/**
 * Plugin Name: AB Payment Router — WooCommerce A-Site Connector
 * Plugin URI: https://github.com/converge/payment-router
 * Description: 将 WooCommerce 订单自动路由到 AB 轮询支付中控，实现多 B 站收款分发。
 * Version: 0.1.0
 * Author: Converge
 * License: GPL-2.0+
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: ab-payment-router
 *
 * @package ABPaymentRouter
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

define('ABPR_VERSION', '0.1.0');
define('ABPR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ABPR_PLUGIN_URL', plugin_dir_url(__FILE__));

// ── Autoload ──
require_once ABPR_PLUGIN_DIR . 'includes/class-api-client.php';
require_once ABPR_PLUGIN_DIR . 'includes/class-checkout-handler.php';
require_once ABPR_PLUGIN_DIR . 'includes/class-webhook-handler.php';
require_once ABPR_PLUGIN_DIR . 'includes/class-admin-settings.php';

// ── Bootstrap ──
add_action('plugins_loaded', function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('AB Payment Router 需要 WooCommerce 插件激活。', 'ab-payment-router');
            echo '</p></div>';
        });
        return;
    }

    ABPR\Checkout_Handler::init();
    ABPR\Webhook_Handler::init();
    ABPR\Admin_Settings::init();
});

// ── Activation / Deactivation ──
register_activation_hook(__FILE__, function (): void {
    if (!get_option('abpr_api_key')) {
        update_option('abpr_api_key', 'ck_' . bin2hex(random_bytes(24)));
    }
    if (!get_option('abpr_controller_url')) {
        update_option('abpr_controller_url', 'https://your-controller.example.com');
    }
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function (): void {
    flush_rewrite_rules();
});
