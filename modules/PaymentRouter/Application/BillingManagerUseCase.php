<?php
/**
 * BillingManagerUseCase — 付款集成
 *
 * P2: Stripe Checkout / Paddle / USDT 三通道。
 * 付款成功 → 自动签发 License → 记录 → 邮件通知。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;
use Converge\Modules\PaymentRouter\Domain\License;

final class BillingManagerUseCase
{
    private DatabaseInterface $db;
    private array $config;

    /** 产品价格表 */
    public const PRODUCTS = [
        'starter_monthly'  => ['tier' => 'starter',  'amount' => 14900, 'currency' => 'usd', 'interval' => 'month'],
        'pro_onetime'      => ['tier' => 'pro',       'amount' => 80000, 'currency' => 'usd', 'interval' => 'lifetime'],
        'pro_renewal'      => ['tier' => 'pro',       'amount' => 15000, 'currency' => 'usd', 'interval' => 'year'],
        'enterprise_onetime'=>['tier' => 'enterprise','amount' =>250000, 'currency' => 'usd', 'interval' => 'lifetime'],
        'enterprise_renewal'=>['tier' => 'enterprise','amount' => 50000, 'currency' => 'usd', 'interval' => 'year'],
    ];

    public function __construct(DatabaseInterface $db, array $config = [])
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * 创建 Stripe Checkout Session。
     */
    public function createStripeCheckout(int $tenantId, string $productId, string $domain): array
    {
        $product = self::PRODUCTS[$productId] ?? throw new \RuntimeException("未知产品: {$productId}");
        $stripeKey = $this->config['stripe_secret_key'] ?? '';

        $isSubscription = $product['interval'] === 'month' || $product['interval'] === 'year';
        $mode = $isSubscription ? 'subscription' : 'payment';

        $priceData = [
            'currency'    => $product['currency'],
            'product_data'=> [
                'name'     => "PaymentRouter " . ucfirst($product['tier']),
                'tax_code' => 'txcd_10000000',
            ],
            'unit_amount' => $product['amount'],
        ];
        if ($isSubscription) {
            $priceData['recurring'] = ['interval' => $product['interval']];
        }

        $sessionData = [
            'line_items' => [[
                'price_data' => $priceData,
                'quantity' => 1,
            ]],
            'mode'          => $mode,
            'success_url'   => ($this->config['base_url'] ?? '') . '/admin?payment=success',
            'cancel_url'    => ($this->config['base_url'] ?? '') . '/admin?payment=cancel',
            'metadata'      => [
                'tenant_id'  => (string)$tenantId,
                'product_id' => $productId,
                'domain'     => $domain,
            ],
        ];

        // 仅 Stripe key 存在时才实际调用
        if ($stripeKey) {
            $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($sessionData),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => "{$stripeKey}:",
                CURLOPT_HTTPHEADER     => ['Stripe-Version: 2025-03-31.basil'],
                CURLOPT_TIMEOUT        => 10,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new \RuntimeException("Stripe error: {$resp}");
            }
            $session = json_decode($resp, true);
            return ['checkout_url' => $session['url'], 'session_id' => $session['id']];
        }

        // 开发环境：返回模拟链接
        return [
            'checkout_url' => ($this->config['base_url'] ?? 'http://localhost:8080') . '/admin?mock_payment=' . $productId,
            'session_id'   => 'dev_' . bin2hex(random_bytes(8)),
            'mode'         => 'development',
        ];
    }

    /**
     * 处理 Stripe Webhook（付款成功回调）。
     */
    public function handleStripeWebhook(string $rawBody, string $sigHeader): array
    {
        $webhookSecret = $this->config['stripe_webhook_secret'] ?? '';

        if ($webhookSecret) {
            $sigParts = explode(',', $sigHeader);
            $timestamp = '';
            $signature = '';
            foreach ($sigParts as $part) {
                if (str_starts_with($part, 't=')) $timestamp = substr($part, 2);
                if (str_starts_with($part, 'v1=')) $signature = substr($part, 3);
            }
            $signedPayload = "{$timestamp}.{$rawBody}";
            $expected = hash_hmac('sha256', $signedPayload, $webhookSecret);
            if (!hash_equals($expected, $signature)) {
                return ['error' => '签名验证失败'];
            }
        }

        $event = json_decode($rawBody, true);
        if (!$event) return ['error' => '无效JSON'];

        $type = $event['type'] ?? '';
        $session = $event['data']['object'] ?? [];
        $metadata = $session['metadata'] ?? [];

        // 处理支付成功
        if ($type === 'checkout.session.completed' || $type === 'invoice.paid') {
            return $this->activateLicense(
                (int)($metadata['tenant_id'] ?? 0),
                $metadata['product_id'] ?? '',
                $metadata['domain'] ?? '',
                $session['id'] ?? '',
                'stripe',
                $session['amount_total'] ?? 0
            );
        }

        // 处理退款
        if ($type === 'charge.refunded') {
            $tenantId = (int)($metadata['tenant_id'] ?? 0);
            $stmt = $this->db->prepare("UPDATE payment_router_payments SET status='refunded' WHERE tx_id=?");
            $txId = $session['id'] ?? '';
            $stmt->bind_param('s', $txId);
            $stmt->execute();
            return ['refunded' => true, 'tx_id' => $txId];
        }

        return ['error' => "未处理的事件类型: {$type}"];
    }

    /**
     * 创建 USDT 支付 (Cryptomus API)。
     * 复用 converge-core 的 Cryptomus 集成逻辑。
     */
    public function createCryptoCheckout(int $tenantId, string $productId, string $domain): array
    {
        $product = self::PRODUCTS[$productId] ?? throw new \RuntimeException("未知产品: {$productId}");
        $apiKey = $this->config['cryptomus_api_key'] ?? '';
        $merchantId = $this->config['cryptomus_merchant_id'] ?? '';

        if (!$apiKey || !$merchantId) {
            // 开发模式：返回模拟数据
            return [
                'checkout_url' => ($this->config['base_url'] ?? '') . '/admin?mock_crypto=' . $productId,
                'session_id'   => 'crypto_' . bin2hex(random_bytes(8)),
                'mode'         => 'development',
                'wallet'       => 'TLgtG6v2xR7NVK8x5EqM5v7oPCfvDXfNxw',
                'network'      => 'TRC20',
                'amount_usdt'  => $product['amount'] / 100,
            ];
        }

        $payload = [
            'amount'    => (string)($product['amount'] / 100),
            'currency'  => 'USD',
            'network'   => 'TRC20',
            'order_id'  => 'pr_' . $tenantId . '_' . time(),
            'url_return'=> ($this->config['base_url'] ?? '') . '/admin?payment=success',
            'url_callback' => ($this->config['base_url'] ?? '') . '/api/payment-router/billing/webhook/crypto',
        ];

        $ch = curl_init('https://api.cryptomus.com/v1/payment');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'merchant: ' . $merchantId,
                'sign: ' . $this->cryptomusSign($payload, $apiKey),
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $data = json_decode($resp ?: '{}', true) ?: [];
        if ($code >= 400 || $error) {
            throw new \RuntimeException("Cryptomus: HTTP {$code} — " . ($data['message'] ?? $error ?? 'unknown'));
        }

        return [
            'checkout_url' => $data['result']['url'] ?? '',
            'session_id'   => $data['result']['uuid'] ?? '',
            'wallet'       => $data['result']['wallet_address'] ?? '',
            'network'      => 'TRC20',
            'amount_usdt'  => $product['amount'] / 100,
            'raw_response' => substr($resp ?: '', 0, 500), // debug
        ];
    }

    /**
     * 创建 Paddle 支付 (支持 PayPal + 国际信用卡)。
     */
    public function createPaddleCheckout(int $tenantId, string $productId): array
    {
        $product = self::PRODUCTS[$productId] ?? throw new \RuntimeException("未知产品: {$productId}");
        $apiKey = $this->config['paddle_api_key'] ?? '';

        if (!$apiKey) {
            return ['checkout_url' => ($this->config['base_url'] ?? '') . '/admin?mock_paddle=' . $productId, 'mode' => 'development'];
        }

        $payload = [
            'items' => [[
                'price' => [
                    'description' => 'PaymentRouter ' . ucfirst($product['tier']),
                    'unit_price'  => ['amount' => (string)($product['amount'] / 100), 'currency_code' => 'USD'],
                ],
                'quantity' => 1,
            ]],
            'custom_data' => ['tenant_id' => $tenantId, 'product_id' => $productId],
        ];

        $ch = curl_init('https://api.paddle.com/transactions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey, 'Paddle-Version: 1'],
        ]);
        $resp = curl_exec($ch); curl_close($ch);
        $data = json_decode($resp ?: '{}', true) ?: [];
        return ['checkout_url' => $data['data']['checkout']['url'] ?? '', 'session_id' => $data['data']['id'] ?? ''];
    }

    /** Cryptomus HMAC 签名 (复用 converge-core 逻辑) */
    private function cryptomusSign(array $data, string $apiKey): string
    {
        ksort($data);
        return md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . $apiKey);
    }

    /**
     * 处理 Cryptomus Webhook 回调。
     */
    public function handleCryptoWebhook(string $rawBody): array
    {
        $data = json_decode($rawBody, true) ?: [];
        $status = $data['status'] ?? '';
        $orderId = $data['order_id'] ?? '';

        // 解析 tenant_id from order_id: pr_{tenantId}_{timestamp}
        $tenantId = 0; $productId = 'pro_onetime';
        if (preg_match('/^pr_(\d+)_/', $orderId, $m)) { $tenantId = (int)$m[1]; }

        if ($status === 'paid' || $status === 'paid_over') {
            $product = self::PRODUCTS[$productId];
            return $this->activateLicense($tenantId, $productId, '*', $data['txid'] ?? $orderId, 'crypto_TRC20', (int)(($product['amount'] ?? 80000)));
        }
        return ['status' => $status, 'order_id' => $orderId];
    }

    /**
     * 处理 USDT 付款确认（手动确认/链上查询）。
     */
    public function confirmCryptoPayment(int $tenantId, string $productId, string $domain, string $txHash, string $network = 'TRC20'): array
    {
        $product = self::PRODUCTS[$productId] ?? throw new \RuntimeException("未知产品: {$productId}");
        return $this->activateLicense($tenantId, $productId, $domain, $txHash, "crypto_{$network}", $product['amount']);
    }

    /**
     * 获取付款历史。
     */
    public function getPaymentHistory(int $tenantId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payment_router_payments WHERE tenant_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bind_param('ii', $tenantId, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * 核心：激活 License。
     */
    private function activateLicense(int $tenantId, string $productId, string $domain, string $txId, string $channel, int $amount): array
    {
        $product = self::PRODUCTS[$productId] ?? ['tier' => 'starter', 'interval' => 'month'];
        $tier = $product['tier'];
        $duration = $product['interval'] === 'lifetime' ? '+100 years' : ($product['interval'] === 'year' ? '+1 year' : '+1 month');

        // 签发 License
        $licenseMgr = new LicenseManagerUseCase($this->db, $this->config['app_secret'] ?? 'change-me');
        $license = $licenseMgr->issue($domain, $tier, $duration);

        // 更新租户套餐
        $stmt = $this->db->prepare("UPDATE payment_router_tenant_config SET tier = ? WHERE tenant_id = ?");
        $stmt->bind_param('si', $tier, $tenantId);
        $stmt->execute();

        // 记录付款
        $stmt2 = $this->db->prepare(
            "INSERT INTO payment_router_payments (tenant_id, product_id, tier, amount, channel, tx_id, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'completed', NOW())"
        );
        $stmt2->bind_param('ississ', $tenantId, $productId, $tier, $amount, $channel, $txId);
        $stmt2->execute();

        // 记录升级
        $stmt3 = $this->db->prepare(
            "INSERT INTO payment_router_upgrade_history (tenant_id, from_tier, to_tier, license_key, upgraded_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $oldTier = 'community';
        $stmt3->bind_param('isss', $tenantId, $oldTier, $tier, $license->licenseKey);
        $stmt3->execute();

        return [
            'activated'   => true,
            'tier'        => $tier,
            'license_key' => $license->licenseKey,
            'expires_at'  => $license->expiresAt,
        ];
    }
}
