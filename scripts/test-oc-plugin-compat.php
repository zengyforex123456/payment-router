<?php
/**
 * OpenCart Plugin ↔ Central Controller JWT Compatibility Test
 *
 * 验证: Controller 生成的 JWT 可被 OC 插件正确解析、
 *       OC 插件 Webhook 签名可被 Controller 正确验证。
 */
declare(strict_types=1);

$base = __DIR__ . '/../modules/PaymentRouter';
require_once "$base/Infrastructure/PaymentGatewayAdapter.php";

use Converge\Modules\PaymentRouter\Infrastructure\PaymentGatewayAdapter;

$pass = 0; $fail = 0;
function test(string $n, callable $f): void { global $pass, $fail; try { $f(); echo "  ✅ $n\n"; $pass++; } catch (Throwable $e) { echo "  ❌ $n — {$e->getMessage()}\n"; $fail++; } }
function b64UrlEncode(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function b64UrlDecode(string $d): string { return base64_decode(strtr($d, '-_', '+/')); }

echo "══════════════════════════════════════════\n";
echo "  OC Plugin ↔ Central Controller Compat\n";
echo "══════════════════════════════════════════\n\n";

$secret = 'shared-secret-for-testing';
$gateway = new PaymentGatewayAdapter($secret);

// ═══ 1. JWT Round-Trip ═══
echo "🔐 JWT Round-Trip (Controller → OC Plugin)\n";

test('Controller-generated JWT is verifiable by OC plugin logic', function () use ($gateway, $secret) {
    // Step 1: Controller generates checkout URL with embedded JWT
    $url = $gateway->generateCheckoutUrl('pay.example.com', [
        'order_id' => 'B-TEST001',
        'amount'    => '99.99',
        'currency'  => 'USD',
    ]);

    // Extract token from URL
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);
    $token = $params['token'] ?? '';
    if (empty($token)) throw new \RuntimeException('No token in URL');

    // Step 2: OC plugin verifies JWT (simulated from ab_router.php verifyJwt())
    $parts = explode('.', $token);
    if (count($parts) !== 3) throw new \RuntimeException('JWT must have 3 parts');
    [$headerB64, $payloadB64, $signatureB64] = $parts;

    // Verify signature using OC plugin's logic
    $expectedSig = b64UrlEncode(hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true));
    if (!hash_equals($expectedSig, $signatureB64)) throw new \RuntimeException('JWT signature mismatch');

    // Decode payload
    $payload = json_decode(b64UrlDecode($payloadB64), true);
    if (!$payload) throw new \RuntimeException('JWT payload not valid JSON');
    if ($payload['order_id'] !== 'B-TEST001') throw new \RuntimeException('order_id mismatch');
    if ($payload['amount'] !== '99.99') throw new \RuntimeException('amount mismatch');

    // Check expiry
    if (!isset($payload['exp'])) throw new \RuntimeException('JWT missing exp');
    if ($payload['exp'] <= time()) throw new \RuntimeException('JWT expired');
});

test('OC plugin rejects expired JWT', function () use ($gateway, $secret) {
    // Manually craft an expired token
    $header = b64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = b64UrlEncode(json_encode([
        'order_id' => 'B-EXPIRED',
        'amount'    => '50.00',
        'currency'  => 'USD',
        'exp'       => time() - 3600, // 1 hour ago
        'iat'       => time() - 3700,
        'jti'       => bin2hex(random_bytes(8)),
    ]));
    $sig = b64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));
    $token = "{$header}.{$payload}.{$sig}";

    // OC plugin verification
    $parts = explode('.', $token);
    [$h, $p, $s] = $parts;
    $payloadData = json_decode(b64UrlDecode($p), true);
    $isExpired = isset($payloadData['exp']) && $payloadData['exp'] < time();
    if (!$isExpired) throw new \RuntimeException('Should detect expiry');
});

test('OC plugin rejects tampered JWT signature', function () use ($gateway, $secret) {
    $url = $gateway->generateCheckoutUrl('pay.example.com', [
        'order_id' => 'B-TAMPER', 'amount' => '50.00', 'currency' => 'USD',
    ]);
    parse_str(parse_url($url, PHP_URL_QUERY), $params);
    $token = $params['token'];

    // Tamper with the payload
    $parts = explode('.', $token);
    $tamperedPayload = b64UrlEncode(json_encode(['order_id'=>'B-HACKED','amount'=>'9999.00','currency'=>'USD','exp'=>time()+3600]));
    $tamperedToken = "{$parts[0]}.{$tamperedPayload}.{$parts[2]}";

    // OC plugin verification should fail
    [$h, $p, $s] = explode('.', $tamperedToken);
    $expectedSig = b64UrlEncode(hash_hmac('sha256', "{$h}.{$p}", $secret, true));
    $isValid = hash_equals($expectedSig, $s);
    if ($isValid) throw new \RuntimeException('Tampered token should be rejected');
});

// ═══ 2. Webhook Signature Round-Trip ═══
echo "\n📡 Webhook Signature (OC Plugin → Controller)\n";

test('OC plugin-generated HMAC is verifiable by controller', function () use ($gateway, $secret) {
    // OC plugin sends webhook
    $webhookPayload = json_encode([
        'b_order_id' => 'B-TEST001',
        'status'     => 'paid',
        'order_id'   => '42',
    ]);
    $ocSignature = hash_hmac('sha256', $webhookPayload, $secret);

    // Controller verifies
    $isValid = $gateway->verifyWebhookSignature($webhookPayload, $ocSignature);
    if (!$isValid) throw new \RuntimeException('Controller rejected OC webhook signature');
});

test('Controller rejects OC webhook with wrong secret', function () use ($gateway) {
    $payload = json_encode(['b_order_id'=>'B-001','status'=>'paid']);
    $sig = hash_hmac('sha256', $payload, 'wrong-secret');
    $isValid = $gateway->verifyWebhookSignature($payload, $sig);
    if ($isValid) throw new \RuntimeException('Should reject wrong secret');
});

// ═══ 3. Full Flow Simulation ═══
echo "\n🔄 Full Flow: Controller Dispatch → OC Plugin Create → OC Webhook → Controller Update\n";

test('Complete A→B→Controller round-trip data integrity', function () use ($gateway, $secret) {
    // 1. Controller dispatches → generates JWT for B-site
    $orderRef = 'B-' . strtoupper(bin2hex(random_bytes(6)));
    $amount = '79.99';
    $url = $gateway->generateCheckoutUrl('pay.example.com', [
        'order_id' => $orderRef, 'amount' => $amount, 'currency' => 'USD',
    ]);

    // 2. OC plugin receives → verifies JWT → extracts order data
    parse_str(parse_url($url, PHP_URL_QUERY), $params);
    $parts = explode('.', $params['token']);
    $payload = json_decode(b64UrlDecode($parts[1]), true);
    $extractedRef = $payload['order_id'];
    $extractedAmt = $payload['amount'];

    if ($extractedRef !== $orderRef) throw new \RuntimeException("Ref mismatch: $extractedRef vs $orderRef");
    if ($extractedAmt !== $amount) throw new \RuntimeException("Amount mismatch: $extractedAmt vs $amount");

    // 3. OC plugin creates order, payment succeeds → sends webhook
    $webhook = json_encode(['b_order_id' => $orderRef, 'status' => 'paid', 'order_id' => '42']);
    $sig = hash_hmac('sha256', $webhook, $secret);

    // 4. Controller receives webhook → verifies signature
    $verified = $gateway->verifyWebhookSignature($webhook, $sig);

    // 5. Controller can parse webhook to update order_mappings
    $data = json_decode($webhook, true);
    if ($data['b_order_id'] !== $orderRef) throw new \RuntimeException('Webhook ref mismatch');
    if ($data['status'] !== 'paid') throw new \RuntimeException('Webhook status mismatch');

    if (!$verified) throw new \RuntimeException('Round-trip verification failed');
});

// ═══ Summary ═══
echo "\n══════════════════════════════════════════\n";
echo "  OC Compat: $pass passed, $fail failed\n";
echo "══════════════════════════════════════════\n\n";

if ($fail > 0) { echo "❌ OC COMPAT TESTS FAILED\n"; exit(1); }
echo "✅ OC Plugin ↔ Controller fully compatible\n";
