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

        if (!$stripeKey || str_starts_with($stripeKey, 'sk_live_xxx')) {
            throw new \RuntimeException('Stripe 支付未配置。请在服务器运行: dokku config:set payment-router STRIPE_SECRET_KEY=sk_live_xxx');
        }

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
            'success_url'   => ($this->config['base_url'] ?? '') . '/app?payment=success',
            'cancel_url'    => ($this->config['base_url'] ?? '') . '/pricing?payment=cancel',
            'metadata'      => [
                'tenant_id'  => (string)$tenantId,
                'product_id' => $productId,
                'domain'     => $domain,
            ],
        ];

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($sessionData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "{$stripeKey}:",
            CURLOPT_HTTPHEADER     => ['Stripe-Version: 2025-03-31.basil'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            $err = json_decode($resp ?: '{}', true) ?: [];
            throw new \RuntimeException('Stripe: ' . ($err['error']['message'] ?? "HTTP {$httpCode}"));
        }
        if ($curlErr) {
            throw new \RuntimeException("Stripe 网络错误: {$curlErr}");
        }

        $session = json_decode($resp, true);
        $url = $session['url'] ?? '';
        if (!$url) {
            throw new \RuntimeException('Stripe 返回异常，未获取到支付链接');
        }
        return ['checkout_url' => $url, 'session_id' => $session['id']];
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
            throw new \RuntimeException('USDT 支付(Cryptomus)未配置。请在服务器运行: dokku config:set payment-router CRYPTOMUS_API_KEY=xxx CRYPTOMUS_MERCHANT_ID=xxx');
        }

        $payload = [
            'amount'    => (string)($product['amount'] / 100),
            'currency'  => 'USD',
            'network'   => 'TRC20',
            'order_id'  => 'pr_' . $tenantId . '_' . time(),
            'url_return'=> ($this->config['base_url'] ?? '') . '/app?payment=success',
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
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $data = json_decode($resp ?: '{}', true) ?: [];
        if ($code >= 400 || $curlErr) {
            throw new \RuntimeException("Cryptomus ({$code}): " . ($data['message'] ?? $curlErr ?? ''));
        }

        $result = $data['result'] ?? [];
        $url = $result['url'] ?? '';
        if (!$url) {
            throw new \RuntimeException('Cryptomus 返回异常: ' . substr($resp ?: '', 0, 200));
        }

        return [
            'checkout_url' => $url,
            'session_id'   => $result['uuid'] ?? '',
            'wallet'       => $result['wallet_address'] ?? '',
            'network'      => 'TRC20',
            'amount_usdt'  => $product['amount'] / 100,
        ];
    }

    /**
     * 创建 Paddle 支付 (支持 PayPal + 国际信用卡)。
     */
    public function createPaddleCheckout(int $tenantId, string $productId): array
    {
        $product = self::PRODUCTS[$productId] ?? throw new \RuntimeException("未知产品: {$productId}");
        $apiKey = $this->config['paddle_api_key'] ?? '';

        if (!$apiKey || str_starts_with($apiKey, 'pdl_xxx')) {
            throw new \RuntimeException('Paddle 支付未配置。请在 Paddle 后台创建产品后，运行: dokku config:set payment-router PADDLE_API_KEY=pdl_xxx');
        }

        // Paddle 需要预先创建 Price ID（通过 Dashboard 或 API）
        // 若未配置 price_id → 使用动态定价模式
        $paddlePriceId = $this->config['paddle_price_' . $productId] ?? '';
        $baseUrl = $this->config['base_url'] ?? 'http://localhost:8080';

        if ($paddlePriceId) {
            // 预配置价格模式（推荐）
            $payload = [
                'items' => [[ 'price_id' => $paddlePriceId, 'quantity' => 1 ]],
                'custom_data' => ['tenant_id' => $tenantId, 'product_id' => $productId],
                'success_url' => $baseUrl . '/app?payment=success',
            ];
        } else {
            // 动态价格模式
            $payload = [
                'items' => [[
                    'price' => [
                        'description' => 'PaymentRouter ' . ucfirst($product['tier']) . ' — ' . $productId,
                        'unit_price'  => ['amount' => (string)($product['amount']), 'currency_code' => 'USD'],
                    ],
                    'quantity' => 1,
                ]],
                'custom_data' => ['tenant_id' => $tenantId, 'product_id' => $productId],
                'success_url' => $baseUrl . '/app?payment=success',
            ];
        }

        $isSandbox = str_starts_with($apiKey, 'pdl_sdbx_');
        $apiHost = $isSandbox ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';

        $ch = curl_init($apiHost . '/checkout');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey, 'Paddle-Version: 1'],
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $data = json_decode($resp ?: '{}', true) ?: [];
        if ($httpCode >= 400 || $curlErr) {
            $msg = $data['error']['detail'] ?? $data['error']['title'] ?? ($curlErr ?: "HTTP {$httpCode}");
            throw new \RuntimeException("Paddle ({$httpCode}): {$msg}");
        }

        $checkoutUrl = $data['data']['url'] ?? ($data['data']['checkout']['url'] ?? '');
        if (!$checkoutUrl) {
            throw new \RuntimeException('Paddle 返回异常: ' . substr($resp ?: '', 0, 300));
        }

        return ['checkout_url' => $checkoutUrl, 'session_id' => $data['data']['id'] ?? ''];
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
