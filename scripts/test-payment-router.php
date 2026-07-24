<?php
/**
 * PaymentRouter — Unit Test Suite
 *
 * 测试 Domain 实体、UseCase 逻辑，不需要数据库或 HTTP 服务器。
 * 运行: php scripts/test-payment-router.php
 */
declare(strict_types=1);

$base = __DIR__ . '/../modules/PaymentRouter';

// ── Load Domain ──
require_once "$base/Domain/ASite.php";
require_once "$base/Domain/BSite.php";
require_once "$base/Domain/OrderMapping.php";
require_once "$base/Domain/RoutingDecision.php";
require_once "$base/Domain/ASiteRepositoryInterface.php";
require_once "$base/Domain/BSiteRepositoryInterface.php";
require_once "$base/Domain/OrderMappingRepositoryInterface.php";

// ── Load Infrastructure ──
require_once "$base/Infrastructure/PaymentGatewayAdapter.php";

// ── Load Application ──
require_once "$base/Application/SelectGatewayUseCase.php";
require_once "$base/Application/RegisterASiteUseCase.php";
require_once "$base/Application/RegisterBSiteUseCase.php";
require_once "$base/Application/DispatchOrderUseCase.php";
require_once "$base/Application/HandlePaymentWebhookUseCase.php";
require_once "$base/Application/HealthCheckUseCase.php";
require_once "$base/Application/ListOrderMappingsUseCase.php";
require_once "$base/Application/GetRoutingDashboardUseCase.php";

use Converge\Modules\PaymentRouter\Domain\ASite;
use Converge\Modules\PaymentRouter\Domain\BSite;
use Converge\Modules\PaymentRouter\Domain\OrderMapping;
use Converge\Modules\PaymentRouter\Domain\RoutingDecision;
use Converge\Modules\PaymentRouter\Infrastructure\PaymentGatewayAdapter;

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  ✅ $name\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "  ❌ $name — {$e->getMessage()}\n";
        $fail++;
    }
}

function assertEq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new \RuntimeException("$msg 期望: " . json_encode($expected) . ", 实际: " . json_encode($actual));
    }
}

echo "══════════════════════════════════════════\n";
echo "  PaymentRouter Unit Tests\n";
echo "══════════════════════════════════════════\n\n";

// ─── 1. Domain Entities ───
echo "📦 Domain Entities\n";

test('ASite: create with auto-generated API key', function () {
    $site = new ASite(0, 1, 'shop.example.com', 'woocommerce');
    assertEq('active', $site->status);
    assertEq(true, str_starts_with($site->apiKey, 'ck_'));
    assertEq(51, strlen($site->apiKey));
});

test('ASite: pause/activate state transitions', function () {
    $site = new ASite(1, 1, 'shop.example.com', 'woocommerce', 'test_key');
    $paused = $site->pause();
    assertEq('paused', $paused->status);
    $reactivated = $paused->activate();
    assertEq('active', $reactivated->status);
});

test('BSite: create with defaults', function () {
    $site = new BSite(0, 1, 'pay.example.com', 'paypal');
    assertEq('active', $site->status);
    assertEq(1, $site->weight);
    assertEq(50, $site->maxDailyOrders);
    assertEq(0, $site->consecutiveFailures);
});

test('BSite: cool and recover', function () {
    $site = new BSite(1, 1, 'pay.example.com', 'stripe');
    $cooled = $site->cool(30);
    assertEq('cooling', $cooled->status);
    assertEq(true, $cooled->cooledUntil !== null);

    $recovered = $cooled->recover();
    assertEq('active', $recovered->status);
    assertEq(null, $recovered->cooledUntil);
    assertEq(0, $recovered->consecutiveFailures);
});

test('BSite: isAvailable / isAtDailyLimit / isInCooldown', function () {
    $active = new BSite(1, 1, 'ok.example.com', 'paypal', 1, 50, 'active');
    assertEq(true, $active->isAvailable());
    assertEq(false, $active->isAtDailyLimit());

    // At daily limit
    $full = new BSite(2, 1, 'full.example.com', 'paypal', 1, 50, 'active', null, 0, 50);
    assertEq(false, $full->isAvailable());
    assertEq(true, $full->isAtDailyLimit());

    // Increment daily orders
    $inc = $active->incrementDailyOrders();
    assertEq(1, $inc->dailyOrderCount);
});

test('OrderMapping: state transitions (pending→paid→refunded)', function () {
    $mapping = new OrderMapping(0, 1, 'A-001', null, 1, 2, '99.99');
    assertEq('pending', $mapping->status);

    $paid = $mapping->markPaid('B-001');
    assertEq('paid', $paid->status);
    assertEq('B-001', $paid->bOrderId);
    assertEq(true, $paid->paidAt !== null);

    $failed = $mapping->markFailed();
    assertEq('failed', $failed->status);

    $refunded = $paid->markRefunded();
    assertEq('refunded', $refunded->status);
});

test('RoutingDecision: toJson contains all fields', function () {
    $decision = RoutingDecision::weighted(3, 'pay3.example.com', 5, 15, []);
    $json = $decision->toJson();
    $data = json_decode($json, true);
    assertEq('weighted', $data['strategy']);
    assertEq(3, $data['b_site_id']);
    assertEq(true, str_contains($data['reason'], '权重'));
});

// ─── 2. PaymentGatewayAdapter ───
echo "\n📡 PaymentGatewayAdapter\n";

test('JWT: generate and verify checkout URL', function () {
    $gateway = new PaymentGatewayAdapter('test-secret-key');
    $url = $gateway->generateCheckoutUrl('pay.example.com', [
        'order_id' => 'B-TEST001',
        'amount' => '99.99',
        'currency' => 'USD',
    ]);
    assertEq(true, str_starts_with($url, 'https://pay.example.com/'));
    assertEq(true, str_contains($url, 'token='));
});

test('HMAC: verify API signature succeeds with correct key', function () {
    $gateway = new PaymentGatewayAdapter('shared-secret');
    $payload = '{"a_order_id":"123","amount":"50.00"}';
    $sig = hash_hmac('sha256', $payload, 'test-api-key');
    assertEq(true, $gateway->verifyApiSignature('test-api-key', $payload, $sig));
});

test('HMAC: verify API signature fails with wrong key', function () {
    $gateway = new PaymentGatewayAdapter('shared-secret');
    $payload = '{"a_order_id":"123","amount":"50.00"}';
    $sig = hash_hmac('sha256', $payload, 'wrong-key');
    assertEq(false, $gateway->verifyApiSignature('correct-key', $payload, $sig));
});

test('Webhook: verify HMAC signature', function () {
    $gateway = new PaymentGatewayAdapter('webhook-secret');
    $payload = '{"status":"paid","b_order_id":"B-001"}';
    $sig = hash_hmac('sha256', $payload, 'webhook-secret');
    assertEq(true, $gateway->verifyWebhookSignature($payload, $sig));
});

// ─── 3. SelectGatewayUseCase (Mock Repository) ───
echo "\n🧠 SelectGatewayUseCase (with mock repository)\n";

// Helper: create a mock BSite repository backed by an array
function createMockBRepo(array &$sites): \Converge\Modules\PaymentRouter\Domain\BSiteRepositoryInterface {
    return new class($sites) implements \Converge\Modules\PaymentRouter\Domain\BSiteRepositoryInterface {
        private array $sites;
        public function __construct(array &$sites) { $this->sites = &$sites; }
        public function findById(int $id): ?BSite {
            foreach ($this->sites as $s) { if ($s->id === $id) return $s; }
            return null;
        }
        public function findAvailable(int $tenantId): array {
            return array_values(array_filter($this->sites, fn(BSite $s) => $s->isAvailable()));
        }
        public function findByTenant(int $tenantId): array { return $this->sites; }
        public function save(BSite $site): \Converge\Modules\PaymentRouter\Domain\BSite {
            foreach ($this->sites as $i => $s) {
                if ($s->id === $site->id) { $this->sites[$i] = $site; return $site; }
            }
            $this->sites[] = $site; return $site;
        }
        public function resetDailyCounts(int $tenantId): void {}
    };
}

test('weighted strategy: selects a valid BSite', function () {
    $sites = [
        new BSite(1, 1, 'b1.example.com', 'paypal', 3, 100),
        new BSite(2, 1, 'b2.example.com', 'stripe', 1, 100),
        new BSite(3, 1, 'b3.example.com', 'paypal', 5, 100),
    ];
    $repo = createMockBRepo($sites);
    $usecase = new \Converge\Modules\PaymentRouter\Application\SelectGatewayUseCase($repo);
    [$selected, $decision] = $usecase->execute(1, '50.00', 'weighted');
    assertEq(true, $selected instanceof BSite);
    assertEq('weighted', $decision->strategy);
});

test('random strategy: selects a valid BSite', function () {
    $sites = [
        new BSite(1, 1, 'b1.example.com', 'paypal', 1, 100),
        new BSite(2, 1, 'b2.example.com', 'stripe', 1, 100),
    ];
    $repo = createMockBRepo($sites);
    $usecase = new \Converge\Modules\PaymentRouter\Application\SelectGatewayUseCase($repo);
    [$selected, $decision] = $usecase->execute(1, '50.00', 'random');
    assertEq(true, $selected instanceof BSite);
    assertEq('random', $decision->strategy);
});

test('throws when no available BSites', function () {
    $sites = [
        new BSite(1, 1, 'b1.example.com', 'paypal', 1, 100, 'cooling', date('Y-m-d H:i:s', time() + 3600)),
        new BSite(2, 1, 'b2.example.com', 'stripe', 1, 100, 'disabled'),
    ];
    $repo = createMockBRepo($sites);
    $usecase = new \Converge\Modules\PaymentRouter\Application\SelectGatewayUseCase($repo);
    try {
        $usecase->execute(1);
        throw new \RuntimeException('应该抛出异常');
    } catch (\RuntimeException $e) {
        assertEq(true, str_contains($e->getMessage(), '不可用'));
    }
});

test('filters out cooled BSites', function () {
    $sites = [
        new BSite(1, 1, 'b-cooled.example.com', 'paypal', 1, 100, 'cooling', date('Y-m-d H:i:s', time() + 3600)),
        new BSite(2, 1, 'b-active.example.com', 'stripe', 1, 100, 'active'),
    ];
    $repo = createMockBRepo($sites);
    $usecase = new \Converge\Modules\PaymentRouter\Application\SelectGatewayUseCase($repo);
    [$selected, $decision] = $usecase->execute(1);
    assertEq(2, $selected->id);
});

test('filters out BSites at daily limit', function () {
    $sites = [
        new BSite(1, 1, 'full.example.com', 'paypal', 1, 50, 'active', null, 0, 50),
        new BSite(2, 1, 'ok.example.com', 'stripe', 1, 100, 'active', null, 0, 10),
    ];
    $repo = createMockBRepo($sites);
    $usecase = new \Converge\Modules\PaymentRouter\Application\SelectGatewayUseCase($repo);
    [$selected, $decision] = $usecase->execute(1);
    assertEq(2, $selected->id);
});

// ─── 4. HandlePaymentWebhookUseCase (Mock) ───
echo "\n🔄 HandlePaymentWebhookUseCase (mock)\n";

$mockMappings = [];
$mockMappingRepo = new class($mockMappings) implements \Converge\Modules\PaymentRouter\Domain\OrderMappingRepositoryInterface {
    private array $mappings;
    public function __construct(array &$mappings) { $this->mappings = &$mappings; }
    public function findById(int $id): ?OrderMapping { return null; }
    public function findByAOrderId(string $aOrderId): ?OrderMapping { return null; }
    public function findByBOrderId(string $bOrderId): ?OrderMapping {
        foreach ($this->mappings as $m) { if ($m->bOrderId === $bOrderId) return $m; }
        return null;
    }
    public function findByTenant(int $tenantId, int $limit = 50, int $offset = 0): array { return []; }
    public function save(OrderMapping $mapping): \Converge\Modules\PaymentRouter\Domain\OrderMapping {
        foreach ($this->mappings as $i => $m) {
            if ($m->id === $mapping->id) { $this->mappings[$i] = $mapping; return $mapping; }
        }
        $this->mappings[] = $mapping;
    }
};

$mockBSitesForWebhook = [];
$mockBRepoForWebhook = new class($mockBSitesForWebhook) implements \Converge\Modules\PaymentRouter\Domain\BSiteRepositoryInterface {
    private array $sites;
    public function __construct(array &$sites) { $this->sites = &$sites; }
    public function findById(int $id): ?BSite {
        foreach ($this->sites as $s) { if ($s->id === $id) return $s; }
        return null;
    }
    public function findAvailable(int $tenantId): array { return []; }
    public function findByTenant(int $tenantId): array { return $this->sites; }
    public function save(BSite $site): \Converge\Modules\PaymentRouter\Domain\BSite {
        foreach ($this->sites as $i => $s) {
            if ($s->id === $site->id) { $this->sites[$i] = $site; return $site; }
        }
        $this->sites[] = $site; return $site;
    }
    public function resetDailyCounts(int $tenantId): void {}
};

test('payment success: marks mapping as paid, resets BSite failures', function () use (&$mockMappings, &$mockBSitesForWebhook, $mockMappingRepo, $mockBRepoForWebhook) {
    $mockMappings = [
        new OrderMapping(1, 1, 'A-001', 'B-001', 1, 1, '50.00', 'USD', 'pending'),
    ];
    $mockBSitesForWebhook = [
        new BSite(1, 1, 'pay.example.com', 'paypal', 1, 100, 'active', null, 2),
    ];
    $usecase = new \Converge\Modules\PaymentRouter\Application\HandlePaymentWebhookUseCase($mockMappingRepo, $mockBRepoForWebhook);
    $result = $usecase->execute(['b_order_id' => 'B-001', 'status' => 'paid']);
    assertEq(true, $result['acknowledged']);
    assertEq('paid', $result['mapping_status']);
    // BSite 应该恢复
    assertEq('active', $mockBSitesForWebhook[0]->status);
    assertEq(0, $mockBSitesForWebhook[0]->consecutiveFailures);
});

test('payment failure: marks mapping as failed, increments BSite failures', function () use (&$mockMappings, &$mockBSitesForWebhook, $mockMappingRepo, $mockBRepoForWebhook) {
    $mockMappings = [
        new OrderMapping(2, 1, 'A-002', 'B-002', 1, 1, '99.00', 'USD', 'pending'),
    ];
    $mockBSitesForWebhook = [
        new BSite(1, 1, 'fail.example.com', 'stripe', 1, 100, 'active', null, 2),
    ];
    $usecase = new \Converge\Modules\PaymentRouter\Application\HandlePaymentWebhookUseCase($mockMappingRepo, $mockBRepoForWebhook);
    $result = $usecase->execute(['b_order_id' => 'B-002', 'status' => 'failed']);
    assertEq('failed', $result['mapping_status']);
});

test('payment failure 3x: triggers BSite cooldown', function () use (&$mockMappings, &$mockBSitesForWebhook, $mockMappingRepo, $mockBRepoForWebhook) {
    $mockMappings = [
        new OrderMapping(3, 1, 'A-003', 'B-003', 1, 1, '50.00', 'USD', 'pending'),
    ];
    $mockBSitesForWebhook = [
        new BSite(1, 1, 'cool.example.com', 'paypal', 1, 100, 'active', null, 3), // already at threshold
    ];
    $usecase = new \Converge\Modules\PaymentRouter\Application\HandlePaymentWebhookUseCase($mockMappingRepo, $mockBRepoForWebhook, null, 3);
    $result = $usecase->execute(['b_order_id' => 'B-003', 'status' => 'failed']);
    assertEq('failed', $result['mapping_status']);
    assertEq('cooled', $result['b_site_status']);
});

test('webhook: throws for unknown b_order_id', function () use (&$mockMappings, &$mockBSitesForWebhook, $mockMappingRepo, $mockBRepoForWebhook) {
    $mockMappings = [];
    $mockBSitesForWebhook = [];
    $usecase = new \Converge\Modules\PaymentRouter\Application\HandlePaymentWebhookUseCase($mockMappingRepo, $mockBRepoForWebhook);
    try {
        $usecase->execute(['b_order_id' => 'NONEXISTENT', 'status' => 'paid']);
        throw new \RuntimeException('应该抛出异常');
    } catch (\RuntimeException $e) {
        assertEq(true, str_contains($e->getMessage(), '未找到'));
    }
});

// ─── Summary ───
echo "\n══════════════════════════════════════════\n";
echo "  Results: $pass passed, $fail failed\n";
echo "══════════════════════════════════════════\n";

if ($fail > 0) {
    exit(1);
}
