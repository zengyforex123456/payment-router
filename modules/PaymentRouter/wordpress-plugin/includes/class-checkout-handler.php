<?php
/**
 * Checkout_Handler — WooCommerce 结账拦截器
 *
 * Hook: woocommerce_checkout_order_processed
 * 订单创建后 → POST 到中控 → 重定向用户到 B 站支付页面。
 * 不修改 WooCommerce 默认支付流程，以插件形式附加。
 */
declare(strict_types=1);

namespace ABPR;

final class Checkout_Handler
{
    public static function init(): void
    {
        $instance = new self();
        // 在订单创建后拦截（此时订单已写入 DB，状态为 pending）
        add_action('woocommerce_checkout_order_processed', [$instance, 'handleCheckout'], 20, 3);
        // 替换默认的 thank-you 重定向
        add_filter('woocommerce_get_return_url', [$instance, 'filterReturnUrl'], 10, 2);
    }

    /**
     * 订单创建后 → 提交到中控 → 保存 B 站跳转 URL 到订单 meta。
     *
     * @param int   $orderId
     * @param array $postedData
     * @param \WC_Order $order
     */
    public function handleCheckout(int $orderId, array $postedData, \WC_Order $order): void
    {
        // 仅处理待支付订单
        if ($order->get_status() !== 'pending') {
            return;
        }

        try {
            $client = new API_Client(
                get_option('abpr_controller_url', ''),
                get_option('abpr_api_key', ''),
                get_option('abpr_secret', '')
            );

            $result = $client->dispatch([
                'order_id' => (string) $orderId,
                'amount'   => (string) $order->get_total(),
                'currency' => $order->get_currency(),
            ]);

            // 保存 B 站信息到订单 meta（用于后续 Webhook 回调匹配）
            update_post_meta($orderId, '_abpr_b_order_ref', $result['b_order_reference']);
            update_post_meta($orderId, '_abpr_b_checkout_url', $result['b_checkout_url']);
            update_post_meta($orderId, '_abpr_b_site_domain', $result['b_site_domain']);

            // 添加订单备注
            $order->add_order_note(sprintf(
                '🔀 已路由到 B 站: %s (Ref: %s)',
                esc_html($result['b_site_domain']),
                esc_html($result['b_order_reference'])
            ));

        } catch (\RuntimeException $e) {
            // 路由失败不阻塞下单——订单保留 pending 状态，管理员手动处理
            $order->add_order_note('⚠️ AB 路由失败: ' . esc_html($e->getMessage()));
            wc_get_logger()->error('AB Payment Router: ' . $e->getMessage(), ['source' => 'ab-payment-router']);
        }
    }

    /**
     * 替换返回 URL 为 B 站 checkout URL。
     *
     * @param string    $returnUrl
     * @param \WC_Order $order
     */
    public function filterReturnUrl(string $returnUrl, \WC_Order $order): string
    {
        $bCheckoutUrl = get_post_meta($order->get_id(), '_abpr_b_checkout_url', true);
        if (!empty($bCheckoutUrl)) {
            return $bCheckoutUrl;
        }
        return $returnUrl;
    }
}
