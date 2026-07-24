<?php
/**
 * AB Payment Router — OpenCart B-Site Payment Extension
 *
 * 接收中控 JWT token → 验证并解析订单 → 创建 OC 订单 → 跳转支付网关 → 回调 Webhook。
 *
 * 路由:
 *   /index.php?route=extension/payment/ab_router/checkout&token=JWT  — 支付入口
 *   /index.php?route=extension/payment/ab_router/callback             — 网关回调
 *
 * OpenCart 3.x 兼容。放在 catalog/controller/extension/payment/ab_router.php
 */
class ControllerExtensionPaymentAbRouter extends Controller
{
    /** @var string 中控 API 地址 */
    private string $controllerUrl;
    /** @var string 中控共享密钥 (用于 JWT 验证) */
    private string $secret;

    public function __construct($registry)
    {
        parent::__construct($registry);
        $this->controllerUrl = rtrim($this->config->get('payment_ab_router_controller_url') ?: '', '/');
        $this->secret = $this->config->get('payment_ab_router_secret') ?: 'change-me';
        $this->load->language('extension/payment/ab_router');
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/ab_router');
    }

    // ── 入口: 中控重定向用户到此 → 验证 JWT → 创建订单 → 跳转支付 ──

    public function checkout(): void
    {
        $token = $this->request->get['token'] ?? '';

        // 1. 解析并验证 JWT
        $orderData = $this->verifyJwt($token);
        if (!$orderData) {
            $this->session->data['error'] = $this->language->get('error_invalid_token');
            $this->response->redirect($this->url->link('common/home'));
            return;
        }

        // 2. 防御: 检查是否已有相同 B-Order-Ref 的订单（防重放）
        $existingOrderId = $this->model_extension_payment_ab_router->findOrderByRef($orderData['order_id']);
        if ($existingOrderId) {
            // 已有订单，直接跳转支付
            $this->response->redirect($this->url->link(
                'extension/payment/ab_router/pay&order_id=' . $existingOrderId
            ));
            return;
        }

        // 3. 创建 OC 订单（使用"访客结账"模式）
        $orderId = $this->createOrder($orderData);
        if (!$orderId) {
            $this->session->data['error'] = $this->language->get('error_order_create');
            $this->response->redirect($this->url->link('checkout/cart'));
            return;
        }

        // 4. 跳转到支付网关
        $this->response->redirect($this->url->link(
            'extension/payment/ab_router/pay&order_id=' . $orderId
        ));
    }

    // ── 支付页面: 调用实际网关（PayPal/Stripe）完成收款 ──

    public function pay(): void
    {
        $orderId = (int)($this->request->get['order_id'] ?? 0);
        if (!$orderId) {
            $this->response->redirect($this->url->link('checkout/cart'));
            return;
        }

        $this->load->model('checkout/order');
        $orderInfo = $this->model_checkout_order->getOrder($orderId);
        if (!$orderInfo) {
            $this->response->redirect($this->url->link('common/home'));
            return;
        }

        // 根据 B 站配置的网关选择实际支付方式
        $gateway = $this->config->get('payment_ab_router_gateway') ?: 'paypal';

        // 设置 session 以便网关使用
        $this->session->data['order_id'] = $orderId;
        $this->session->data['payment_address'] = $orderInfo['payment_address_1'] ?? '';
        $this->session->data['currency'] = $orderInfo['currency_code'] ?? 'USD';

        // 重定向到实际支付网关
        switch ($gateway) {
            case 'stripe':
                $this->response->redirect($this->url->link('extension/payment/stripe/checkout'));
                break;
            case 'paypal':
            default:
                $this->response->redirect($this->url->link('extension/payment/pp_standard/checkout'));
                break;
        }
    }

    // ── 网关回调: 支付成功后网关回调此端点 ──

    public function callback(): void
    {
        $orderId = (int)($this->request->get['order_id'] ?? 0);
        if (!$orderId) {
            // 尝试从 session 获取
            $orderId = (int)($this->session->data['order_id'] ?? 0);
        }
        if (!$orderId) { exit('Missing order_id'); }

        $this->load->model('checkout/order');
        $orderInfo = $this->model_checkout_order->getOrder($orderId);
        if (!$orderInfo) { exit('Order not found'); }

        // 获取 B-Order-Ref（由创建订单时保存）
        $bOrderRef = $this->model_extension_payment_ab_router->getOrderRef($orderId);
        $orderStatusId = (int)$orderInfo['order_status_id'];

        // 判断支付结果 (不同的网关有不同的成功状态 ID)
        $paidStatuses = [
            $this->config->get('payment_pp_standard_completed_status_id'),
            $this->config->get('payment_stripe_completed_status_id'),
            5,  // OpenCart default: Complete
            15, // OpenCart default: Processed
        ];

        $isPaid = in_array($orderStatusId, $paidStatuses, true);

        // POST Webhook 回中控
        $this->sendWebhook($bOrderRef, $isPaid ? 'paid' : 'failed', $orderId);

        if ($isPaid) {
            $this->response->redirect($this->url->link('checkout/success'));
        } else {
            $this->session->data['error'] = $this->language->get('error_payment_failed');
            $this->response->redirect($this->url->link('checkout/failure'));
        }
    }

    // ── 私有方法 ──

    /**
     * 简易 JWT 验证（HS256，零依赖）。
     *
     * @return array{order_id:string,amount:string,currency:string}|null
     */
    private function verifyJwt(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // 验证签名
        $expectedSig = self::base64UrlEncode(
            hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $this->secret, true)
        );
        if (!hash_equals($expectedSig, $signatureB64)) return null;

        // 解码 payload
        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!$payload) return null;

        // 验证时效（15 分钟）
        if (isset($payload['exp']) && $payload['exp'] < time()) return null;

        // 检查必要字段
        if (empty($payload['order_id']) || empty($payload['amount'])) return null;

        return $payload;
    }

    /**
     * 创建 OC 订单（访客结账模式，最小信息）。
     */
    private function createOrder(array $orderData): int
    {
        try {
            $data = [
                'invoice_prefix' => 'AB-',
                'store_id'       => $this->config->get('config_store_id'),
                'store_name'     => $this->config->get('config_name'),
                'store_url'      => $this->config->get('config_url'),
                'customer_id'    => 0, // 访客
                'customer_group_id' => (int)$this->config->get('config_customer_group_id'),
                'firstname'      => 'Customer',
                'lastname'       => 'Online',
                'email'          => 'order@ab-payment.local',
                'telephone'      => '000-000-0000',
                'comment'        => 'AB Router Order — Ref: ' . $orderData['order_id'],
                'payment_method' => 'ab_router',
                'payment_code'   => 'ab_router',
                'shipping_method'=> 'free',
                'shipping_code'  => 'free.free',
                'total'          => $orderData['amount'],
                'currency_code'  => $orderData['currency'] ?? 'USD',
                'currency_value' => 1.0,
                'order_status_id'=> 1, // Pending
                'products' => [[
                    'product_id'   => (int)$this->config->get('payment_ab_router_product_id') ?: 1,
                    'name'         => 'Order #' . substr($orderData['order_id'], 0, 20),
                    'model'        => 'AB-ORDER',
                    'quantity'     => 1,
                    'price'        => (float)$orderData['amount'],
                    'total'        => (float)$orderData['amount'],
                    'tax'          => 0,
                    'reward'       => 0,
                ]],
            ];

            $orderId = $this->model_checkout_order->addOrder($data);

            // 保存 B-Order-Ref 映射
            $this->model_extension_payment_ab_router->saveOrderRef($orderId, $orderData['order_id']);

            $this->log->write("AB Router: Created B-Site order #{$orderId} for A-Ref: {$orderData['order_id']}");

            return $orderId;
        } catch (\Exception $e) {
            $this->log->write("AB Router: Failed to create order — {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * 发送 Webhook 回中控。
     */
    private function sendWebhook(string $bOrderRef, string $status, int $orderId): void
    {
        if (empty($this->controllerUrl)) return;

        $payload = json_encode([
            'b_order_id' => $bOrderRef,
            'status'     => $status,
            'order_id'   => (string)$orderId,
        ]);

        $signature = hash_hmac('sha256', $payload, $this->secret);

        $ch = curl_init("{$this->controllerUrl}/api/payment-router/webhook");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Signature: ' . $signature,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->log->write("AB Router: Webhook sent — ref={$bOrderRef} status={$status} http={$httpCode}");
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
