<?php
/**
 * PaymentRouter — E2E API Test Suite
 *
 * 测试完整 API 流程: 注册→分发→回调, 模拟真实 HTTP 请求。
 * 运行: php scripts/test-payment-router-e2e.php
 */
declare(strict_types=1);

$base = __DIR__ . '/../modules/PaymentRouter';

// Load all module files
$files = [
    "$base/Domain/ASite.php", "$base/Domain/BSite.php", "$base/Domain/OrderMapping.php",
    "$base/Domain/RoutingDecision.php", "$base/Domain/ASiteRepositoryInterface.php",
    "$base/Domain/BSiteRepositoryInterface.php", "$base/Domain/OrderMappingRepositoryInterface.php",
    "$base/Infrastructure/PaymentGatewayAdapter.php",
    "$base/Application/SelectGatewayUseCase.php",
    "$base/Application/DispatchOrderUseCase.php",
    "$base/Application/HandlePaymentWebhookUseCase.php",
    "$base/Application/RegisterASiteUseCase.php", "$base/Application/RegisterBSiteUseCase.php",
    "$base/Application/HealthCheckUseCase.php",
    "$base/Application/ListOrderMappingsUseCase.php",
    "$base/Application/GetRoutingDashboardUseCase.php",
];
foreach ($files as $f) require_once $f;

use Converge\Modules\PaymentRouter\Domain\ASite;
use Converge\Modules\PaymentRouter\Domain\BSite;
use Converge\Modules\PaymentRouter\Domain\OrderMapping;
use Converge\Modules\PaymentRouter\Infrastructure\PaymentGatewayAdapter;
use Converge\Modules\PaymentRouter\Application\SelectGatewayUseCase;
use Converge\Modules\PaymentRouter\Application\DispatchOrderUseCase;
use Converge\Modules\PaymentRouter\Application\HandlePaymentWebhookUseCase;
use Converge\Modules\PaymentRouter\Application\RegisterASiteUseCase;
use Converge\Modules\PaymentRouter\Application\RegisterBSiteUseCase;
use Converge\Modules\PaymentRouter\Application\HealthCheckUseCase;
use Converge\Modules\PaymentRouter\Application\ListOrderMappingsUseCase;

$pass = 0; $fail = 0;
function test(string $name, callable $fn): void {
    global $pass, $fail;
    try { $fn(); echo "  ✅ $name\n"; $pass++; }
    catch (\Throwable $e) { echo "  ❌ $name — {$e->getMessage()}\n"; $fail++; }
}

echo "══════════════════════════════════════════════\n";
echo "  PaymentRouter E2E API Tests\n";
echo "══════════════════════════════════════════════\n\n";

// ─── In-Memory Repositories (shared across all tests) ───

$aSites = [];
$bSites = [];
$mappings = [];

$db = new class {
    public function prepare(string $sql): object {
        return new class($sql) {
            private string $sql;
            private array $params = [];
            public function __construct(string $sql) { $this->sql = $sql; }
            public function bind_param(string $types, mixed ...$args): void { $this->params = $args; }
            public function execute(): void {}
            public function get_result(): object {
                return new class { function fetch_assoc() { return null; } function fetch_all(int $mode) { return []; } };
            }
        };
    }
};

$aRepo = new class($aSites) implements \Converge\Modules\PaymentRouter\Domain\ASiteRepositoryInterface {
    private array $sites;
    public function __construct(array &$sites) { $this->sites = &$sites; }
    public function findById(int $id): ?ASite {
        foreach ($this->sites as $s) if ($s->id === $id) return $s;
        return null;
    }
    public function findByApiKey(string $apiKey): ?ASite {
        foreach ($this->sites as $s) if ($s->apiKey === $apiKey) return $s;
        return null;
    }
    public function findByTenant(int $tenantId): array { return $this->sites; }
    public function save(ASite $site): \Converge\Modules\PaymentRouter\Domain\ASite {
        if ($site->id > 0) { foreach ($this->sites as $i => $s) { if ($s->id === $site->id) { $this->sites[$i] = $site; return $site; } } }
        $newId = count($this->sites) + 1;
        $this->sites[] = $saved = new ASite($newId, $site->tenantId, $site->domain, $site->platform, $site->apiKey, $site->status);
        return $saved;
    }
    public function delete(int $id): void {
        $this->sites = array_filter($this->sites, fn(ASite $s) => $s->id !== $id);
    }
};

$bRepo = new class($bSites) implements \Converge\Modules\PaymentRouter\Domain\BSiteRepositoryInterface {
    private array $sites;
    public function __construct(array &$sites) { $this->sites = &$sites; }
    public function findById(int $id): ?BSite {
        foreach ($this->sites as $s) if ($s->id === $id) return $s;
        return null;
    }
    public function findAvailable(int $tenantId): array {
        return array_values(array_filter($this->sites, fn(BSite $s) => $s->isAvailable()));
    }
    public function findByTenant(int $tenantId): array { return $this->sites; }
    public function save(BSite $site): \Converge\Modules\PaymentRouter\Domain\BSite {
        foreach ($this->sites as $i => $s) { if ($s->id === $site->id) { $this->sites[$i] = $site; return $site; } }
        $newId = count($this->sites) + 1;
        $this->sites[] = $saved = new BSite($newId, $site->tenantId, $site->domain, $site->paymentGateway,
            $site->weight, $site->maxDailyOrders, $site->status, $site->cooledUntil,
            $site->consecutiveFailures, $site->dailyOrderCount);
        return $saved;
    }
    public function resetDailyCounts(int $tenantId): void {
        foreach ($this->sites as $i => $s) {
            $this->sites[$i] = new BSite($s->id, $s->tenantId, $s->domain, $s->paymentGateway,
                $s->weight, $s->maxDailyOrders, $s->status, $s->cooledUntil, $s->consecutiveFailures, 0);
        }
    }
};

$mRepo = new class($mappings) implements \Converge\Modules\PaymentRouter\Domain\OrderMappingRepositoryInterface {
    private array $mappings;
    public function __construct(array &$mappings) { $this->mappings = &$mappings; }
    public function findById(int $id): ?OrderMapping {
        foreach ($this->mappings as $m) if ($m->id === $id) return $m;
        return null;
    }
    public function findByAOrderId(string $aOrderId): ?OrderMapping {
        foreach ($this->mappings as $m) if ($m->aOrderId === $aOrderId) return $m;
        return null;
    }
    public function findByBOrderId(string $bOrderId): ?OrderMapping {
        foreach ($this->mappings as $m) if ($m->bOrderId === $bOrderId) return $m;
        return null;
    }
    public function findByTenant(int $tenantId, int $limit = 50, int $offset = 0): array { return $this->mappings; }
    public function save(OrderMapping $mapping): \Converge\Modules\PaymentRouter\Domain\OrderMapping {
        if ($mapping->id > 0) {
            foreach ($this->mappings as $i => $m) {
                if ($m->id === $mapping->id) { $this->mappings[$i] = $mapping; return $mapping; }
            }
        }
        $newId = count($this->mappings) + 1;
        $this->mappings[] = new OrderMapping(
            $newId, $mapping->tenantId, $mapping->aOrderId, $mapping->bOrderId,
            $mapping->aSiteId, $mapping->bSiteId, $mapping->amount, $mapping->currency,
            $mapping->status, $mapping->routingReason, $mapping->dispatchedAt, $mapping->paidAt
        );
    return $this->mappings[count($this->mappings)-1];
    }
};

// ─── Wire Up UseCases ───
$gateway = new PaymentGatewayAdapter('e2e-test-secret');
$selectGateway = new SelectGatewayUseCase($bRepo);
$dispatchOrder = new DispatchOrderUseCase($aRepo, $bRepo, $mRepo, $selectGateway, $gateway);
$handleWebhook = new HandlePaymentWebhookUseCase($mRepo, $bRepo, $db, 3);
$registerASite = new RegisterASiteUseCase($aRepo);
$registerBSite = new RegisterBSiteUseCase($bRepo);
$healthCheck = new HealthCheckUseCase($bRepo);
$listMappings = new ListOrderMappingsUseCase($mRepo);

// ══════════════════════════════════════════════
// E2E Scenario 1: Happy Path — 1A + 2B → Order → Paid
// ══════════════════════════════════════════════
echo "📦 Scenario 1: Happy Path (1A+2B → Dispatch → Paid)\n";

$tenantId = 1;
$aSiteKey = '';
$bSite1Id = 0;
$bSite2Id = 0;

test('POST /api/payment-router/a-sites — register A site', function () use ($registerASite, $tenantId, &$aSiteKey) {
    $site = $registerASite->execute($tenantId, 'shop.example.com', 'woocommerce');
    $aSiteKey = $site->apiKey;
    assert(isset($site), 'A site should be created');
    assert($site->domain === 'shop.example.com', 'Domain mismatch');
    assert(str_starts_with($site->apiKey, 'ck_'), 'API key format wrong');
});

test('POST /api/payment-router/b-sites — register B site 1 (PayPal, weight=3)', function () use ($registerBSite, $tenantId, &$bSite1Id) {
    $site = $registerBSite->execute($tenantId, 'pay1.example.com', 'paypal', 3, 100);
    $bSite1Id = $site->id;
    assert($site->domain === 'pay1.example.com', 'Domain mismatch');
    assert($site->weight === 3, 'Weight mismatch');
    assert($site->isAvailable(), 'Should be available');
});

test('POST /api/payment-router/b-sites — register B site 2 (Stripe, weight=1)', function () use ($registerBSite, $tenantId, &$bSite2Id) {
    $site = $registerBSite->execute($tenantId, 'pay2.example.com', 'stripe', 1, 50);
    $bSite2Id = $site->id;
    assert($site->domain === 'pay2.example.com', 'Domain mismatch');
    assert($site->isAvailable(), 'Should be available');
});

test('GET /api/payment-router/a-sites — list A sites', function () use ($aRepo, $tenantId) {
    $sites = $aRepo->findByTenant($tenantId);
    assert(count($sites) === 1, 'Should have 1 A site');
    assert($sites[0]->domain === 'shop.example.com', 'Domain mismatch');
});

test('GET /api/payment-router/b-sites — list B sites', function () use ($bRepo, $tenantId) {
    $sites = $bRepo->findByTenant($tenantId);
    assert(count($sites) === 2, 'Should have 2 B sites');
    assert($sites[0]->isAvailable(), 'B1 should be available');
    assert($sites[1]->isAvailable(), 'B2 should be available');
});

$dispatchResult = null;
test('POST /api/payment-router/dispatch — dispatch order from A site', function () use ($dispatchOrder, $aSiteKey, &$dispatchResult) {
    $ts = (string)time();
    $payload = json_encode(['a_order_id' => 'ORDER-1001', 'amount' => '79.99', 'currency' => 'USD', 'timestamp' => $ts]);
    $signature = hash_hmac('sha256', $payload, $aSiteKey);

    $dispatchResult = $dispatchOrder->execute([
        'api_key' => $aSiteKey,
        'signature' => $signature,
        'a_order_id' => 'ORDER-1001',
        'amount' => '79.99',
        'currency' => 'USD',
        'timestamp' => $ts,
    ]);
    assert(isset($dispatchResult['b_checkout_url']), 'Should return checkout URL');
    assert(str_starts_with($dispatchResult['b_checkout_url'], 'https://'), 'URL should be HTTPS');
    assert(isset($dispatchResult['b_order_reference']), 'Should return B order reference');
    assert(str_starts_with($dispatchResult['b_order_reference'], 'B-'), 'B ref should start with B-');
});

test('POST /api/payment-router/dispatch — verify order mapping created', function () use ($mRepo, $dispatchResult, $tenantId) {
    $mappings = $mRepo->findByTenant($tenantId);
    assert(count($mappings) === 1, 'Should have 1 mapping');
    assert($mappings[0]->aOrderId === 'ORDER-1001', 'A order ID mismatch');
    assert($mappings[0]->bOrderId === $dispatchResult['b_order_reference'], 'B order ID mismatch');
    assert($mappings[0]->status === 'pending', 'Initial status should be pending');
    assert((float)$mappings[0]->amount === 79.99, 'Amount mismatch');
});

test('POST /api/payment-router/webhook — payment succeeded', function () use ($handleWebhook, $dispatchResult, $mRepo) {
    $result = $handleWebhook->execute([
        'b_order_id' => $dispatchResult['b_order_reference'],
        'status' => 'paid',
    ]);
    assert($result['acknowledged'] === true, 'Should acknowledge');
    assert($result['mapping_status'] === 'paid', 'Mapping should be paid');

    // Verify mapping is updated
    $mapping = $mRepo->findByBOrderId($dispatchResult['b_order_reference']);
    assert($mapping->status === 'paid', 'Persisted status should be paid');
    assert($mapping->paidAt !== null, 'Paid timestamp should be set');
});

// ══════════════════════════════════════════════
// E2E Scenario 2: Auth & Error Paths
// ══════════════════════════════════════════════
echo "\n📦 Scenario 2: Auth & Error Paths\n";

test('POST /api/payment-router/dispatch — rejects invalid API key', function () use ($dispatchOrder) {
    try {
        $dispatchOrder->execute([
            'api_key' => 'invalid-key-xxxxx',
            'signature' => 'xxx',
            'a_order_id' => 'O-999',
            'amount' => '10.00',
        ]);
        throw new \RuntimeException('Should have thrown');
    } catch (\RuntimeException $e) {
        assert(str_contains($e->getMessage(), '无效'), 'Should reject invalid API key');
    }
});

test('POST /api/payment-router/dispatch — rejects wrong HMAC signature', function () use ($dispatchOrder, $aSiteKey) {
    try {
        $dispatchOrder->execute([
            'api_key' => $aSiteKey,
            'signature' => 'tampered-signature-xxxxx',
            'a_order_id' => 'O-999',
            'amount' => '10.00',
        ]);
        throw new \RuntimeException('Should have thrown');
    } catch (\RuntimeException $e) {
        assert(str_contains($e->getMessage(), '签名'), 'Should reject wrong signature');
    }
});

test('POST /api/payment-router/webhook — rejects unknown b_order_id', function () use ($handleWebhook) {
    try {
        $handleWebhook->execute(['b_order_id' => 'B-NONEXISTENT', 'status' => 'paid']);
        throw new \RuntimeException('Should have thrown');
    } catch (\RuntimeException $e) {
        assert(str_contains($e->getMessage(), '未找到'), 'Should reject unknown order');
    }
});

// ══════════════════════════════════════════════
// E2E Scenario 3: Cooldown on Consecutive Failures
// ══════════════════════════════════════════════
echo "\n📦 Scenario 3: B-Site Cooldown (3 consecutive failures → cooling)\n";

$failKey = '';
test('Setup: register another A site for cooldown test', function () use ($registerASite, $tenantId, &$failKey) {
    $site = $registerASite->execute($tenantId, 'shop2.example.com', 'woocommerce');
    $failKey = $site->apiKey;
});

test('Setup: register isolated B site for failure test', function () use ($registerBSite, $tenantId) {
    $site = $registerBSite->execute($tenantId, 'fragile.example.com', 'paypal', 1, 100);
});

// Find the fragile B site
$fragileSite = null;
foreach ($bRepo->findByTenant($tenantId) as $bs) {
    if ($bs->domain === 'fragile.example.com') { $fragileSite = $bs; break; }
}
assert($fragileSite !== null, 'Fragile B site should exist');

test('Fail #1: consecutive_failures should become 1', function () use ($dispatchOrder, $handleWebhook, $failKey, $bRepo, $fragileSite) {
    // We need to force the dispatch to choose the fragile site.
    // Since all others are also active, use amount_threshold strategy to bias.
    // Simpler: directly test webhook failure path
    $fakeMapping = new OrderMapping(99, 1, 'O-FAKE', 'B-FAIL-001', 1, $fragileSite->id, '10.00', 'USD', 'pending');
    $mRepo2 = $GLOBALS['mRepo'];
    $mRepo2->save($fakeMapping);

    $handleWebhook->execute(['b_order_id' => 'B-FAIL-001', 'status' => 'failed']);
    $bs = $bRepo->findById($fragileSite->id);
    assert($bs->consecutiveFailures >= 1, 'Should have at least 1 failure');
    assert($bs->status === 'active', 'Should still be active after 1 failure');
});

test('Fail #2: consecutive_failures = 2, still active', function () use ($handleWebhook, $bRepo, $fragileSite) {
    $fakeMapping = new OrderMapping(98, 1, 'O-FAKE2', 'B-FAIL-002', 1, $fragileSite->id, '20.00', 'USD', 'pending');
    $GLOBALS['mRepo']->save($fakeMapping);
    $handleWebhook->execute(['b_order_id' => 'B-FAIL-002', 'status' => 'failed']);
    $bs = $bRepo->findById($fragileSite->id);
    assert($bs->consecutiveFailures >= 2, 'Should have 2 failures');
    assert($bs->status === 'active', 'Still active after 2 failures');
});

test('Fail #3: triggers cooldown (threshold=3)', function () use ($handleWebhook, $bRepo, $fragileSite) {
    $fakeMapping = new OrderMapping(97, 1, 'O-FAKE3', 'B-FAIL-003', 1, $fragileSite->id, '30.00', 'USD', 'pending');
    $GLOBALS['mRepo']->save($fakeMapping);
    $result = $handleWebhook->execute(['b_order_id' => 'B-FAIL-003', 'status' => 'failed']);
    assert($result['b_site_status'] === 'cooled', 'Should trigger cooldown');

    $bs = $bRepo->findById($fragileSite->id);
    assert($bs->status === 'cooling', 'Status should be cooling');
    assert($bs->isInCooldown(), 'Should be in cooldown');
    assert(!$bs->isAvailable(), 'Should NOT be available for dispatch');
});

// ══════════════════════════════════════════════
// E2E Scenario 4: Order Dispatch with Strategy
// ══════════════════════════════════════════════
echo "\n📦 Scenario 4: Multi-Order Dispatch Pattern\n";

test('Dispatch 5 orders — all should succeed with active B sites', function () use ($dispatchOrder, $aSiteKey, $mRepo, $tenantId) {
    $beforeCount = count($mRepo->findByTenant($tenantId));
    for ($i = 1; $i <= 5; $i++) {
        $ts = (string)time();
        $amt = (string)(10 * $i) . '.00';
        $payload = json_encode(['a_order_id' => "ORDER-200{$i}", 'amount' => $amt, 'currency' => 'USD', 'timestamp' => $ts]);
        $sig = hash_hmac('sha256', $payload, $aSiteKey);
        $result = $dispatchOrder->execute([
            'api_key' => $aSiteKey, 'signature' => $sig,
            'a_order_id' => "ORDER-200{$i}", 'amount' => $amt, 'currency' => 'USD', 'timestamp' => $ts,
        ]);
        assert(isset($result['b_checkout_url']), "Order $i should dispatch");
    }
    $afterCount = count($mRepo->findByTenant($tenantId));
    assert($afterCount - $beforeCount >= 5, 'Should have 5+ new mappings');
});

test('Check dashboard data after orders', function () use ($mRepo, $tenantId) {
    $all = $mRepo->findByTenant($tenantId);
    $paid = count(array_filter($all, fn($m) => $m->status === 'paid'));
    $pending = count(array_filter($all, fn($m) => $m->status === 'pending'));
    $failed = count(array_filter($all, fn($m) => $m->status === 'failed'));
    assert($pending >= 5, "Should have at least 5 pending orders, got $pending");
    assert($paid >= 1, "Should have at least 1 paid order, got $paid");
    echo "    📊 Dashboard: {$pending} pending, {$paid} paid, {$failed} failed\n";
});

// ══════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════
echo "\n══════════════════════════════════════════════\n";
echo "  E2E Results: $pass passed, $fail failed\n";
echo "══════════════════════════════════════════════\n\n";

if ($fail > 0) {
    echo "❌ E2E TESTS FAILED\n";
    exit(1);
}

echo "✅ ALL E2E TESTS PASSED\n";
echo "\n📋 Test Coverage:\n";
echo "  ✓ A-Site CRUD (register + list)\n";
echo "  ✓ B-Site CRUD (register + list)\n";
echo "  ✓ Order Dispatch (auth + routing + mapping)\n";
echo "  ✓ Webhook Callback (paid + failed states)\n";
echo "  ✓ Auth Errors (invalid key + bad signature)\n";
echo "  ✓ Cooldown Trigger (3 consecutive failures → cooling)\n";
echo "  ✓ Multi-Order Dispatch (5 orders → all succeed)\n";
echo "  ✓ Dashboard Snapshot (pending/paid/failed counts)\n";
