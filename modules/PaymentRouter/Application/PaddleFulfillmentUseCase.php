<?php
/**
 * PaddleFulfillmentUseCase — Paddle 履约层
 *
 * 三部分:
 *  1. Webhook 签名验证 + 事件路由 (idempotent upsert)
 *  2. 订阅/客户状态镜像到数据库
 *  3. 客户 Portal Session 创建
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;

final class PaddleFulfillmentUseCase
{
    private DatabaseInterface $db;
    private string $apiKey;
    private string $webhookSecret;
    private string $env;

    public function __construct(DatabaseInterface $db, array $config = [])
    {
        $this->db = $db;
        $this->apiKey = $config['paddle_api_key'] ?? '';
        $this->webhookSecret = $config['paddle_webhook_secret'] ?? '';
        $this->env = $config['paddle_environment'] ?? 'sandbox';
    }

    // ════════════════════════════════════════
    // Part 1: Webhook Handler
    // ════════════════════════════════════════

    /**
     * 验证 Paddle Webhook 签名。返回解析后的事件数据。
     * 注意: $rawBody 必须是原始请求体（不能 JSON.parse 后再传）。
     */
    public function verifyWebhook(string $rawBody, string $signatureHeader): ?array
    {
        if (!$this->webhookSecret) {
            // 无 secret 时跳过验证（开发模式）
            return json_decode($rawBody, true);
        }

        // Paddle webhook 签名格式: ts={timestamp};h1={signature}
        $parts = [];
        foreach (explode(';', $signatureHeader) as $p) {
            $kv = explode('=', trim($p), 2);
            if (count($kv) === 2) $parts[$kv[0]] = $kv[1];
        }
        $ts = $parts['ts'] ?? '';
        $h1 = $parts['h1'] ?? '';

        if (!$ts || !$h1) return null;

        // 验证签名: HMAC-SHA256(ts + '.' + rawBody, webhookSecret)
        $signedPayload = $ts . '.' . $rawBody;
        $expected = hash_hmac('sha256', $signedPayload, $this->webhookSecret);
        if (!hash_equals($expected, $h1)) return null;

        return json_decode($rawBody, true);
    }

    /**
     * 处理已验证的 Paddle 事件。Idempotent upsert。
     */
    public function handleEvent(array $event): array
    {
        $type = $event['event_type'] ?? '';
        $data = $event['data'] ?? [];

        return match ($type) {
            'customer.created', 'customer.updated' => $this->upsertCustomer($data),
            'subscription.created', 'subscription.updated' => $this->upsertSubscription($data),
            'subscription.canceled' => $this->cancelSubscription($data),
            'transaction.completed' => $this->handleTransactionCompleted($data),
            'transaction.payment_failed' => $this->handleTransactionFailed($data),
            default => ['handled' => false, 'event_type' => $type],
        };
    }

    // ════════════════════════════════════════
    // Part 2: Database Mirroring
    // ════════════════════════════════════════

    private function upsertCustomer(array $data): array
    {
        $cid  = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        if (!$cid) return ['error' => 'missing customer id'];

        $stmt = $this->db->prepare(
            'INSERT INTO payment_router_customers (customer_id, email, paddle_customer_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE email=VALUES(email), paddle_customer_id=VALUES(paddle_customer_id), updated_at=NOW()'
        );
        $internalId = 'cust_' . $cid;
        $stmt->bind_param('sss', $internalId, $email, $cid);
        $stmt->execute();

        return ['customer_id' => $internalId, 'action' => 'upserted'];
    }

    private function upsertSubscription(array $data): array
    {
        $subId = $data['id'] ?? '';
        $custPaddleId = $data['customer_id'] ?? '';
        $status = $data['status'] ?? '';
        $priceId = $data['items'][0]['price_id'] ?? '';
        $productId = $data['items'][0]['price']['product_id'] ?? '';
        $scheduled = $data['scheduled_change'] ?? null;

        if (!$subId) return ['error' => 'missing subscription id'];

        // Ensure customer exists
        $internalCustId = 'cust_' . $custPaddleId;
        $stmt = $this->db->prepare('SELECT customer_id FROM payment_router_customers WHERE customer_id = ?');
        $stmt->bind_param('s', $internalCustId);
        $stmt->execute();
        if (!$stmt->get_result()->num_rows) {
            // Auto-create customer record
            $stmt2 = $this->db->prepare('INSERT INTO payment_router_customers (customer_id, email, paddle_customer_id) VALUES (?, ?, ?)');
            $email = $data['email'] ?? '';
            $stmt2->bind_param('sss', $internalCustId, $email, $custPaddleId);
            $stmt2->execute();
        }

        $stmt3 = $this->db->prepare(
            'INSERT INTO payment_router_subscriptions
             (subscription_id, customer_id, status, price_id, product_id,
              scheduled_change_action, scheduled_change_at, current_period_start, current_period_end)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
              status=VALUES(status), price_id=VALUES(price_id), product_id=VALUES(product_id),
              scheduled_change_action=VALUES(scheduled_change_action), scheduled_change_at=VALUES(scheduled_change_at),
              current_period_start=VALUES(current_period_start), current_period_end=VALUES(current_period_end), updated_at=NOW()'
        );
        $scAction = $scheduled['action'] ?? null;
        $scAt = $this->toMysqlDatetime($scheduled['effective_at'] ?? null);
        $periodStart = $this->toMysqlDatetime($data['current_billing_period']['started_at'] ?? null);
        $periodEnd = $this->toMysqlDatetime($data['current_billing_period']['ends_at'] ?? null);
        $stmt3->bind_param('sssssssss', $subId, $internalCustId, $status, $priceId, $productId, $scAction, $scAt, $periodStart, $periodEnd);
        $stmt3->execute();

        return ['subscription_id' => $subId, 'action' => 'upserted', 'status' => $status];
    }

    private function cancelSubscription(array $data): array
    {
        $subId = $data['id'] ?? '';
        if (!$subId) return ['error' => 'missing subscription id'];

        $stmt = $this->db->prepare(
            "UPDATE payment_router_subscriptions SET status='canceled', canceled_at=NOW(), updated_at=NOW() WHERE subscription_id=?"
        );
        $stmt->bind_param('s', $subId);
        $stmt->execute();

        return ['subscription_id' => $subId, 'action' => 'canceled'];
    }

    private function handleTransactionCompleted(array $data): array
    {
        $txId = $data['id'] ?? '';
        $subId = $data['subscription_id'] ?? '';
        $custId = $data['customer_id'] ?? '';
        $amount = (int)(($data['details']['totals']['subtotal'] ?? '0') * 100); // convert to cents

        // Record payment
        $stmt = $this->db->prepare(
            "INSERT INTO payment_router_payments (tenant_id, product_id, tier, amount, channel, tx_id, status, created_at)
             VALUES (0, 'paddle_sub', 'starter', ?, 'paddle', ?, 'completed', NOW())"
        );
        $stmt->bind_param('is', $amount, $txId);
        $stmt->execute();

        return ['transaction_id' => $txId, 'action' => 'recorded', 'amount' => $amount];
    }

    private function handleTransactionFailed(array $data): array
    {
        $txId = $data['id'] ?? '';
        $stmt = $this->db->prepare("UPDATE payment_router_payments SET status='failed' WHERE tx_id=?");
        $stmt->bind_param('s', $txId);
        $stmt->execute();
        return ['transaction_id' => $txId, 'action' => 'marked_failed'];
    }

    // ════════════════════════════════════════
    // Part 3: Customer Portal
    // ════════════════════════════════════════

    /**
     * 创建 Paddle Customer Portal Session。
     * 客户自助: 更新支付方式、取消订阅、查看账单。
     */
    public function createPortalSession(string $customerId): array
    {
        if (!$this->apiKey) {
            return ['error' => 'Paddle API key not configured'];
        }

        // Look up Paddle customer ID from our DB
        $stmt = $this->db->prepare('SELECT paddle_customer_id FROM payment_router_customers WHERE customer_id = ?');
        $stmt->bind_param('s', $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $paddleCustId = $row['paddle_customer_id'] ?? null;
        if (!$paddleCustId) return ['error' => 'No Paddle customer linked'];

        // Look up active subscription
        $stmt2 = $this->db->prepare(
            "SELECT subscription_id FROM payment_router_subscriptions WHERE customer_id=? AND status IN ('active','trialing') LIMIT 1"
        );
        $stmt2->bind_param('s', $customerId);
        $stmt2->execute();
        $sub = $stmt2->get_result()->fetch_assoc();
        $subId = $sub['subscription_id'] ?? null;

        $apiBase = $this->env === 'production' ? 'https://api.paddle.com' : 'https://sandbox-api.paddle.com';
        $payload = ['customer_id' => $paddleCustId];
        if ($subId) $payload['subscription_id'] = $subId;

        $ch = curl_init($apiBase . '/customers/' . $paddleCustId . '/portal-sessions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey, 'Paddle-Version: 1'],
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp ?: '{}', true) ?: [];

        return ['portal_url' => $data['data']['url'] ?? '', 'customer_id' => $customerId];
    }

    /** ISO 8601 → MySQL datetime */
    private function toMysqlDatetime(?string $iso): ?string
    {
        if (!$iso) return null;
        // '2026-07-24T00:00:00Z' → '2026-07-24 00:00:00'
        return date('Y-m-d H:i:s', strtotime($iso));
    }

    /**
     * 检查订阅是否授予付费访问权限。
     * active + trialing = 有权限。仅 canceled 撤销。
     */
    public function hasAccess(string $customerId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT status FROM payment_router_subscriptions WHERE customer_id=? AND status IN ('active','trialing') LIMIT 1"
        );
        $stmt->bind_param('s', $customerId);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }
}
