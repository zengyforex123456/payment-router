<?php
/**
 * Webhook_Handler — 中控支付回调接收器
 *
 * 端点: /wp-json/abpr/v1/webhook
 * 中控支付成功后 POST 到此端点 → 验证签名 → 更新 WP 订单状态。
 */
declare(strict_types=1);

namespace ABPR;

final class Webhook_Handler
{
    public static function init(): void
    {
        $instance = new self();
        add_action('rest_api_init', [$instance, 'registerRoute']);
    }

    /**
     * 注册 REST API 端点: POST /wp-json/abpr/v1/webhook
     */
    public function registerRoute(): void
    {
        register_rest_route('abpr/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleWebhook'],
            'permission_callback' => '__return_true', // 签名验证替代传统认证
        ]);
    }

    /**
     * 处理支付回调。
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handleWebhook(\WP_REST_Request $request): \WP_REST_Response
    {
        $rawBody = $request->get_body();
        $params = json_decode($rawBody, true);

        if (!$params) {
            return new \WP_REST_Response(['error' => '无效的 JSON 请求体'], 400);
        }

        $bOrderRef = $params['b_order_id'] ?? '';
        $status = $params['status'] ?? '';
        $transactionId = $params['transaction_id'] ?? '';

        // 1. 验证 HMAC 签名
        $signatureHeader = $request->get_header('X-Signature') ?? '';
        $client = new API_Client(
            get_option('abpr_controller_url', ''),
            get_option('abpr_api_key', '')
        );

        if (!$client->verifyWebhook($rawBody, $signatureHeader)) {
            wc_get_logger()->warning("Webhook 签名验证失败: b_order={$bOrderRef}", ['source' => 'ab-payment-router']);
            return new \WP_REST_Response(['error' => '签名验证失败'], 401);
        }

        // 2. 查找 WP 订单（遍历以 _abpr_b_order_ref 匹配）
        $orderId = $this->findOrderByBRef($bOrderRef);
        if (!$orderId) {
            return new \WP_REST_Response(['error' => "未找到订单: {$bOrderRef}"], 404);
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return new \WP_REST_Response(['error' => "订单不存在: {$orderId}"], 404);
        }

        // 3. 更新订单状态
        switch ($status) {
            case 'paid':
                $order->payment_complete($transactionId);
                $order->add_order_note("✅ B 站支付成功 (Ref: {$bOrderRef}, Txn: {$transactionId})");
                break;
            case 'failed':
                $order->update_status('failed', "❌ B 站支付失败 (Ref: {$bOrderRef})");
                break;
            case 'refunded':
                $order->update_status('refunded', "↩️ B 站已退款 (Ref: {$bOrderRef})");
                break;
            default:
                return new \WP_REST_Response(['error' => "未知状态: {$status}"], 400);
        }

        wc_get_logger()->info("Webhook 处理成功: order={$orderId} b_ref={$bOrderRef} status={$status}", ['source' => 'ab-payment-router']);

        return new \WP_REST_Response(['acknowledged' => true, 'order_id' => $orderId, 'new_status' => $order->get_status()], 200);
    }

    /**
     * 通过 B 站订单引用查找 WP 订单 ID。
     */
    private function findOrderByBRef(string $bOrderRef): ?int
    {
        $query = new \WP_Query([
            'post_type'      => 'shop_order',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_abpr_b_order_ref',
            'meta_value'     => $bOrderRef,
        ]);

        return $query->have_posts() ? $query->posts[0] : null;
    }
}
