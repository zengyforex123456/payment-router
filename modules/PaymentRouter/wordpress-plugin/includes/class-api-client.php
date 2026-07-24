<?php
/**
 * API_Client — 与 PaymentRouter 中控的 HTTP 通信
 *
 * 职责: HMAC 签名生成、POST dispatch 端点、验证 Webhook 签名。
 * 零业务逻辑，纯 HTTP + 加密。
 */
declare(strict_types=1);

namespace ABPR;

final class API_Client
{
    private string $controllerUrl;
    private string $apiKey;
    private string $secret;

    public function __construct(string $controllerUrl, string $apiKey, string $secret = '')
    {
        $this->controllerUrl = rtrim($controllerUrl, '/');
        $this->apiKey = $apiKey;
        $this->secret = $secret !== '' ? $secret : $apiKey;
    }

    /**
     * 分发订单到中控，返回 B 站跳转 URL。
     *
     * @param array{order_id:string,amount:string,currency:string} $order
     * @return array{b_checkout_url:string,b_order_reference:string,b_site_domain:string}
     * @throws \RuntimeException
     */
    public function dispatch(array $order): array
    {
        $timestamp = (string) time();
        $payload = json_encode([
            'a_order_id' => $order['order_id'],
            'amount'      => $order['amount'],
            'currency'    => $order['currency'] ?? 'USD',
            'timestamp'   => $timestamp,
        ], JSON_UNESCAPED_SLASHES);

        $signature = hash_hmac('sha256', $payload, $this->apiKey);

        $response = wp_remote_post("{$this->controllerUrl}/api/payment-router/dispatch", [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Signature'  => $signature,
                'X-Api-Key'    => $this->apiKey,
            ],
            'body' => json_encode([
                'api_key'   => $this->apiKey,
                'signature' => $signature,
                'a_order_id' => $order['order_id'],
                'amount'     => $order['amount'],
                'currency'   => $order['currency'] ?? 'USD',
                'timestamp'  => $timestamp,
            ]),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('中控连接失败: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200 || !$body) {
            throw new \RuntimeException('中控返回错误 [' . $code . ']: ' . ($body['error'] ?? '未知错误'));
        }

        if (empty($body['b_checkout_url'])) {
            throw new \RuntimeException('中控未返回 B 站跳转 URL');
        }

        return $body;
    }

    /**
     * 验证来自中控的 Webhook HMAC 签名。
     */
    public function verifyWebhook(string $rawBody, string $signatureHeader): bool
    {
        $expected = hash_hmac('sha256', $rawBody, $this->apiKey);
        return hash_equals($expected, $signatureHeader);
    }
}
