<?php
/**
 * PaymentGatewayAdapter — 支付网关适配器
 *
 * 为各支付通道提供统一接口：生成跳转 URL、验证 Webhook 签名、处理回调。
 * 新增支付通道 = 新增 Adapter 方法，不改核心引擎。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Infrastructure;

final class PaymentGatewayAdapter
{
    private string $secretKey;

    public function __construct(string $secretKey = '')
    {
        $this->secretKey = $secretKey !== '' ? $secretKey : ($_ENV['APP_SECRET'] ?? 'change-me');
    }

    /**
     * 生成 B 站支付跳转 URL（含一次性 JWT token）
     *
     * @param string $bSiteDomain B 站域名
     * @param array $order 订单数据 {order_id, amount, currency}
     * @return string B 站 checkout URL
     */
    public function generateCheckoutUrl(string $bSiteDomain, array $order): string
    {
        $payload = [
            'order_id' => $order['order_id'],
            'amount' => $order['amount'],
            'currency' => $order['currency'] ?? 'USD',
            'exp' => time() + 300, // 5 分钟时效 (安全加固)
            'iat' => time(),
            'jti' => bin2hex(random_bytes(8)),
        ];

        $token = $this->signJwt($payload);
        $query = http_build_query(['token' => $token]);

        return "https://{$bSiteDomain}/index.php?route=payment/router/checkout&{$query}";
    }

    /**
     * 验证 Webhook HMAC 签名
     *
     * @param string $payload 原始请求体
     * @param string $signatureHeader 请求头中的签名
     * @return bool 签名有效
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        $expected = hash_hmac('sha256', $payload, $this->secretKey);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * 验证外部 API 请求的 HMAC 签名（A 站→中控）
     *
     * @param string $apiKey API Key
     * @param string $payload 请求体
     * @param string $signature 请求头中的签名
     * @return bool 签名有效
     */
    public function verifyApiSignature(string $apiKey, string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $payload, $apiKey);

        return hash_equals($expected, $signature);
    }

    /** 生成 B 站 Webhook 回调 URL */
    public function getWebhookUrl(string $bSiteDomain, string $bOrderId): string
    {
        $payload = json_encode(['b_order_id' => $bOrderId]);
        $signature = hash_hmac('sha256', $payload, $this->secretKey);

        return "https://{$bSiteDomain}/index.php?route=payment/router/webhook";
    }

    /**
     * 生成 POST 模式 Bearer Token（替代 URL 参数，防日志/历史泄露）。
     */
    public function generateBearerToken(array $order): string
    {
        return $this->signJwt([
            'order_id' => $order['order_id'],
            'amount'   => $order['amount'],
            'currency' => $order['currency'] ?? 'USD',
            'exp'      => time() + 300,
            'iat'      => time(),
            'jti'      => bin2hex(random_bytes(8)),
        ]);
    }

    /**
     * 简易 JWT 签名（HS256，零依赖）
     */
    private function signJwt(array $payload): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payloadEncoded}", $this->secretKey, true)
        );

        return "{$header}.{$payloadEncoded}.{$signature}";
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
