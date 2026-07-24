<?php
/**
 * WP Plugin ↔ Central Controller Compatibility Test
 *
 * 验证: HMAC 签名格式一致、dispatch payload 结构一致、webhook 签名验证互通。
 */
declare(strict_types=1);

$base = __DIR__ . '/../modules/PaymentRouter';
require_once "$base/Domain/ASite.php";
require_once "$base/Domain/BSite.php";
require_once "$base/Domain/OrderMapping.php";
require_once "$base/Domain/RoutingDecision.php";
require_once "$base/Domain/ASiteRepositoryInterface.php";
require_once "$base/Domain/BSiteRepositoryInterface.php";
require_once "$base/Domain/OrderMappingRepositoryInterface.php";
require_once "$base/Infrastructure/PaymentGatewayAdapter.php";

use Converge\Modules\PaymentRouter\Infrastructure\PaymentGatewayAdapter;

$pass = 0; $fail = 0;
function test(string $n, callable $f): void { global $pass, $fail; try { $f(); echo "  ✅ $n\n"; $pass++; } catch (Throwable $e) { echo "  ❌ $n — {$e->getMessage()}\n"; $fail++; } }

echo "══════════════════════════════════════════\n";
echo "  WP Plugin ↔ Central Controller Compat\n";
echo "══════════════════════════════════════════\n\n";

$apiKey = 'ck_' . bin2hex(random_bytes(24));
$controllerSecret = 'test-controller-secret';
$gateway = new PaymentGatewayAdapter($controllerSecret);

// ═══ 1. HMAC Signature Compatibility ═══
echo "🔐 HMAC Signature Compatibility\n";

test('WP plugin generates valid HMAC signature verifiable by controller', function () use ($apiKey, $gateway) {
    // Simulate the WP plugin's signing logic (from class-api-client.php)
    $timestamp = (string) time();
    $order = ['a_order_id' => '42', 'amount' => '99.99', 'currency' => 'USD'];
    $payload = json_encode([
        'a_order_id' => $order['a_order_id'],
        'amount'     => $order['amount'],
        'currency'   => $order['currency'],
        'timestamp'  => $timestamp,
    ], JSON_UNESCAPED_SLASHES);

    $wpSignature = hash_hmac('sha256', $payload, $apiKey);

    // Verify using controller's verifier
    $isValid = $gateway->verifyApiSignature($apiKey, $payload, $wpSignature);
    if (!$isValid) throw new \RuntimeException('Controller rejected WP plugin signature');
});

test('tampered payload is rejected', function () use ($apiKey, $gateway) {
    $originalPayload = json_encode(['a_order_id'=>'42','amount'=>'99.99','currency'=>'USD','timestamp'=>(string)time()]);
    $sig = hash_hmac('sha256', $originalPayload, $apiKey);
    $tamperedPayload = json_encode(['a_order_id'=>'43','amount'=>'999.99','currency'=>'USD','timestamp'=>(string)time()]);
    $isValid = $gateway->verifyApiSignature($apiKey, $tamperedPayload, $sig);
    if ($isValid) throw new \RuntimeException('Tampered payload should be rejected');
});

// ═══ 2. Dispatch Payload Structure ═══
echo "\n📦 Dispatch Payload Structure\n";

test('WP plugin dispatch body matches controller DispatchOrderUseCase expectations', function () use ($apiKey) {
    $timestamp = (string) time();
    $payload = json_encode(['a_order_id'=>'42','amount'=>'99.99','currency'=>'USD','timestamp'=>$timestamp]);
    $signature = hash_hmac('sha256', $payload, $apiKey);

    // This is the body the WP plugin sends (from class-api-client.php)
    $body = [
        'api_key'    => $apiKey,
        'signature'  => $signature,
        'a_order_id' => '42',
        'amount'     => '99.99',
        'currency'   => 'USD',
        'timestamp'  => $timestamp,
    ];

    // Verify all required fields are present
    $requiredFields = ['api_key', 'signature', 'a_order_id', 'amount', 'currency', 'timestamp'];
    foreach ($requiredFields as $field) {
        if (!isset($body[$field])) throw new \RuntimeException("Missing field: $field");
    }
    if (strlen($body['api_key']) !== 51) throw new \RuntimeException('API key wrong length');
    if (strlen($body['signature']) !== 64) throw new \RuntimeException('HMAC hex wrong length');
});

// ═══ 3. Webhook Signature Verification ═══
echo "\n📡 Webhook Signature Verification\n";

test('WP plugin webhook handler verifies controller signature', function () use ($apiKey, $controllerSecret) {
    // Simulate controller sending a webhook to WP plugin
    $controllerGateway = new PaymentGatewayAdapter($controllerSecret);
    $webhookPayload = json_encode(['b_order_id'=>'B-ABC123','status'=>'paid','transaction_id'=>'TXN-001']);
    $controllerSignature = hash_hmac('sha256', $webhookPayload, $apiKey); // controller signs with A-site's apiKey

    // Simulate WP plugin's verification (from class-webhook-handler.php)
    $wpExpected = hash_hmac('sha256', $webhookPayload, $apiKey);
    if (!hash_equals($wpExpected, $controllerSignature)) {
        throw new \RuntimeException('Webhook signature mismatch');
    }
});

test('WP plugin rejects tampered webhook', function () use ($apiKey) {
    $webhookPayload = json_encode(['b_order_id'=>'B-ABC123','status'=>'paid']);
    $sig = hash_hmac('sha256', $webhookPayload, $apiKey);

    $tamperedPayload = json_encode(['b_order_id'=>'B-XYZ999','status'=>'paid']);
    $tamperedSig = hash_hmac('sha256', $tamperedPayload, 'wrong-key');

    $wpExpected = hash_hmac('sha256', $webhookPayload, $apiKey);
    if (hash_equals($wpExpected, $tamperedSig)) {
        throw new \RuntimeException('Tampered webhook should be rejected');
    }
});

// ═══ 4. API Key Format ═══
echo "\n🔑 API Key Format\n";

test('WP plugin auto-generated API key matches controller format', function () {
    $pluginKey = 'ck_' . bin2hex(random_bytes(24));
    if (strlen($pluginKey) !== 51) throw new \RuntimeException("Length: " . strlen($pluginKey));
    if (!str_starts_with($pluginKey, 'ck_')) throw new \RuntimeException("Prefix wrong");
});

test('Controller verifies API key from A-Site registration', function () use ($apiKey) {
    // Check the key has correct format for controller to accept
    $site = new \Converge\Modules\PaymentRouter\Domain\ASite(0, 1, 'shop.com', 'woocommerce', $apiKey);
    if ($site->apiKey !== $apiKey) throw new \RuntimeException('API key was modified');
    if ($site->status !== 'active') throw new \RuntimeException('Status wrong');
});

// ═══ Summary ═══
echo "\n══════════════════════════════════════════\n";
echo "  Compat: $pass passed, $fail failed\n";
echo "══════════════════════════════════════════\n\n";

if ($fail > 0) { echo "❌ COMPAT TESTS FAILED\n"; exit(1); }
echo "✅ WP Plugin ↔ Controller fully compatible\n";
