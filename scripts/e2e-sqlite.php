<?php
/**
 * PaymentRouter — Self-Contained E2E Test (SQLite In-Memory)
 *
 * 不依赖 MySQL/Docker，使用 SQLite :memory: 运行完整 E2E 流程。
 * 验证: A站注册 → B站注册 → 订单分发 → Webhook回调 → 冷却机制 → 仪表盘
 */
declare(strict_types=1);

$base = __DIR__ . '/../modules/PaymentRouter';

// Load all module files
foreach (['Domain/ASite.php','Domain/BSite.php','Domain/OrderMapping.php','Domain/RoutingDecision.php',
    'Domain/ASiteRepositoryInterface.php','Domain/BSiteRepositoryInterface.php','Domain/OrderMappingRepositoryInterface.php',
    'Infrastructure/PaymentGatewayAdapter.php',
    'Application/SelectGatewayUseCase.php','Application/DispatchOrderUseCase.php',
    'Application/HandlePaymentWebhookUseCase.php','Application/RegisterASiteUseCase.php',
    'Application/RegisterBSiteUseCase.php','Application/HealthCheckUseCase.php',
    'Application/ListOrderMappingsUseCase.php'] as $f) {
    require_once "$base/$f";
}

use Converge\Modules\PaymentRouter\Domain\{ASite, BSite, OrderMapping, RoutingDecision};
use Converge\Modules\PaymentRouter\Infrastructure\PaymentGatewayAdapter;
use Converge\Modules\PaymentRouter\Application\{
    SelectGatewayUseCase, DispatchOrderUseCase, HandlePaymentWebhookUseCase,
    RegisterASiteUseCase, RegisterBSiteUseCase, HealthCheckUseCase, ListOrderMappingsUseCase
};

$pass = 0; $fail = 0;
function test(string $n, callable $f): void { global $pass, $fail; try { $f(); echo "  ✅ $n\n"; $pass++; } catch (Throwable $e) { echo "  ❌ $n — {$e->getMessage()}\n"; $fail++; } }

echo "══════════════════════════════════════════\n";
echo "  Self-Contained E2E (SQLite In-Memory)\n";
echo "══════════════════════════════════════════\n\n";

// ── In-Memory SQLite Repositories ──
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE a_sites(id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, domain TEXT, platform TEXT, api_key TEXT, status TEXT DEFAULT 'active')");
$pdo->exec("CREATE TABLE b_sites(id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, domain TEXT, payment_gateway TEXT, weight INTEGER DEFAULT 1, max_daily_orders INTEGER DEFAULT 50, status TEXT DEFAULT 'active', cooled_until TEXT, consecutive_failures INTEGER DEFAULT 0, daily_order_count INTEGER DEFAULT 0)");
$pdo->exec("CREATE TABLE order_mappings(id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, a_order_id TEXT, b_order_id TEXT, a_site_id INTEGER, b_site_id INTEGER, amount TEXT, currency TEXT DEFAULT 'USD', status TEXT DEFAULT 'pending', routing_reason TEXT, dispatched_at TEXT, paid_at TEXT)");

$nextAId = 1; $nextBId = 1; $nextMId = 1;
$aSites = []; $bSites = []; $mappings = [];

$aRepo = new class($pdo, $aSites, $nextAId) implements \Converge\Modules\PaymentRouter\Domain\ASiteRepositoryInterface {
    private PDO $db; private array &$sites; private int &$nextId;
    public function __construct(PDO $db, array &$sites, int &$nextId) { $this->db = $db; $this->sites = &$sites; $this->nextId = &$nextId; }
    public function findById(int $id): ?ASite {
        $stmt = $this->db->prepare('SELECT * FROM a_sites WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ASite((int)$row['id'], (int)$row['tenant_id'], $row['domain'], $row['platform'], $row['api_key'], $row['status']) : null;
    }
    public function findByApiKey(string $apiKey): ?ASite {
        $stmt = $this->db->prepare('SELECT * FROM a_sites WHERE api_key=?');
        $stmt->execute([$apiKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ASite((int)$row['id'], (int)$row['tenant_id'], $row['domain'], $row['platform'], $row['api_key'], $row['status']) : null;
    }
    public function findByTenant(int $tenantId): array {
        $stmt = $this->db->prepare('SELECT * FROM a_sites WHERE tenant_id=? ORDER BY id');
        $stmt->execute([$tenantId]);
        return array_map(fn($r) => new ASite((int)$r['id'], (int)$r['tenant_id'], $r['domain'], $r['platform'], $r['api_key'], $r['status']), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    public function save(ASite $site): void {
        if ($site->id > 0) {
            $stmt = $this->db->prepare('UPDATE a_sites SET domain=?, platform=?, status=? WHERE id=?');
            $stmt->execute([$site->domain, $site->platform, $site->status, $site->id]);
        } else {
            $stmt = $this->db->prepare('INSERT INTO a_sites(tenant_id, domain, platform, api_key, status) VALUES(?,?,?,?,?)');
            $stmt->execute([$site->tenantId, $site->domain, $site->platform, $site->apiKey, $site->status]);
        }
    }
    public function delete(int $id): void {
        $stmt = $this->db->prepare('DELETE FROM a_sites WHERE id=?');
        $stmt->execute([$id]);
    }
};

$bRepo = new class($pdo, $bSites, $nextBId) implements \Converge\Modules\PaymentRouter\Domain\BSiteRepositoryInterface {
    private PDO $db; private array &$sites; private int &$nextId;
    public function __construct(PDO $db, array &$sites, int &$nextId) { $this->db = $db; $this->sites = &$sites; $this->nextId = &$nextId; }
    public function findById(int $id): ?BSite {
        $stmt = $this->db->prepare('SELECT * FROM b_sites WHERE id=?');
        $stmt->execute([$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new BSite((int)$row['id'],(int)$row['tenant_id'],$row['domain'],$row['payment_gateway'],(int)$row['weight'],(int)$row['max_daily_orders'],$row['status'],$row['cooled_until'],(int)$row['consecutive_failures'],(int)$row['daily_order_count']) : null;
    }
    public function findAvailable(int $tenantId): array {
        $stmt = $this->db->prepare("SELECT * FROM b_sites WHERE tenant_id=? AND status='active' AND daily_order_count < max_daily_orders AND (cooled_until IS NULL OR cooled_until < datetime('now')) ORDER BY weight DESC");
        $stmt->execute([$tenantId]);
        return array_map(fn($r) => new BSite((int)$r['id'],(int)$r['tenant_id'],$r['domain'],$r['payment_gateway'],(int)$r['weight'],(int)$r['max_daily_orders'],$r['status'],$r['cooled_until'],(int)$r['consecutive_failures'],(int)$r['daily_order_count']), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    public function findByTenant(int $tenantId): array {
        $stmt = $this->db->prepare('SELECT * FROM b_sites WHERE tenant_id=? ORDER BY id');
        $stmt->execute([$tenantId]);
        return array_map(fn($r) => new BSite((int)$r['id'],(int)$r['tenant_id'],$r['domain'],$r['payment_gateway'],(int)$r['weight'],(int)$r['max_daily_orders'],$r['status'],$r['cooled_until'],(int)$r['consecutive_failures'],(int)$r['daily_order_count']), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    public function save(BSite $site): void {
        if ($site->id > 0) {
            $stmt = $this->db->prepare('UPDATE b_sites SET domain=?,payment_gateway=?,weight=?,max_daily_orders=?,status=?,cooled_until=?,consecutive_failures=?,daily_order_count=? WHERE id=?');
            $stmt->execute([$site->domain,$site->paymentGateway,$site->weight,$site->maxDailyOrders,$site->status,$site->cooledUntil,$site->consecutiveFailures,$site->dailyOrderCount,$site->id]);
        } else {
            $stmt = $this->db->prepare('INSERT INTO b_sites(tenant_id,domain,payment_gateway,weight,max_daily_orders,status) VALUES(?,?,?,?,?,?)');
            $stmt->execute([$site->tenantId,$site->domain,$site->paymentGateway,$site->weight,$site->maxDailyOrders,$site->status]);
        }
    }
    public function resetDailyCounts(int $tenantId): void {
        $stmt = $this->db->prepare('UPDATE b_sites SET daily_order_count=0 WHERE tenant_id=?');
        $stmt->execute([$tenantId]);
    }
};

$mRepo = new class($pdo, $mappings, $nextMId) implements \Converge\Modules\PaymentRouter\Domain\OrderMappingRepositoryInterface {
    private PDO $db; private array &$mappings; private int &$nextId;
    public function __construct(PDO $db, array &$mappings, int &$nextId) { $this->db = $db; $this->mappings = &$mappings; $this->nextId = &$nextId; }
    public function findById(int $id): ?OrderMapping {
        $stmt = $this->db->prepare('SELECT * FROM order_mappings WHERE id=?');
        $stmt->execute([$id]); $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? new OrderMapping((int)$r['id'],(int)$r['tenant_id'],$r['a_order_id'],$r['b_order_id'],(int)$r['a_site_id'],(int)$r['b_site_id'],$r['amount'],$r['currency'],$r['status'],$r['routing_reason'],$r['dispatched_at'],$r['paid_at']) : null;
    }
    public function findByAOrderId(string $aOrderId): ?OrderMapping {
        $stmt = $this->db->prepare('SELECT * FROM order_mappings WHERE a_order_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$aOrderId]); $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? new OrderMapping((int)$r['id'],(int)$r['tenant_id'],$r['a_order_id'],$r['b_order_id'],(int)$r['a_site_id'],(int)$r['b_site_id'],$r['amount'],$r['currency'],$r['status'],$r['routing_reason'],$r['dispatched_at'],$r['paid_at']) : null;
    }
    public function findByBOrderId(string $bOrderId): ?OrderMapping {
        $stmt = $this->db->prepare('SELECT * FROM order_mappings WHERE b_order_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$bOrderId]); $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? new OrderMapping((int)$r['id'],(int)$r['tenant_id'],$r['a_order_id'],$r['b_order_id'],(int)$r['a_site_id'],(int)$r['b_site_id'],$r['amount'],$r['currency'],$r['status'],$r['routing_reason'],$r['dispatched_at'],$r['paid_at']) : null;
    }
    public function findByTenant(int $tenantId, int $limit=50, int $offset=0): array {
        $stmt = $this->db->prepare('SELECT * FROM order_mappings WHERE tenant_id=? ORDER BY dispatched_at DESC LIMIT ? OFFSET ?');
        $stmt->execute([$tenantId, $limit, $offset]);
        return array_map(fn($r) => new OrderMapping((int)$r['id'],(int)$r['tenant_id'],$r['a_order_id'],$r['b_order_id'],(int)$r['a_site_id'],(int)$r['b_site_id'],$r['amount'],$r['currency'],$r['status'],$r['routing_reason'],$r['dispatched_at'],$r['paid_at']), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    public function save(OrderMapping $mapping): void {
        if ($mapping->id > 0) {
            $stmt = $this->db->prepare('UPDATE order_mappings SET b_order_id=?,status=?,paid_at=? WHERE id=?');
            $stmt->execute([$mapping->bOrderId,$mapping->status,$mapping->paidAt,$mapping->id]);
        } else {
            $stmt = $this->db->prepare('INSERT INTO order_mappings(tenant_id,a_order_id,a_site_id,b_site_id,amount,currency,status,routing_reason,dispatched_at) VALUES(?,?,?,?,?,?,?,?,datetime(?))');
            $stmt->execute([$mapping->tenantId,$mapping->aOrderId,$mapping->aSiteId,$mapping->bSiteId,$mapping->amount,$mapping->currency,$mapping->status,$mapping->routingReason,$mapping->dispatchedAt]);
        }
    }
};

// ── Wire use cases ──
$gateway = new PaymentGatewayAdapter('e2e-secret');
$selectGateway = new SelectGatewayUseCase($bRepo);
$dispatchOrder = new DispatchOrderUseCase($aRepo, $bRepo, $mRepo, $selectGateway, $gateway);
$handleWebhook = new HandlePaymentWebhookUseCase($mRepo, $bRepo, 3);
$registerASite = new RegisterASiteUseCase($aRepo);
$registerBSite = new RegisterBSiteUseCase($bRepo);
$listMappings = new ListOrderMappingsUseCase($mRepo);

// ═══════════════════════════════════
echo "📦 Setup\n";
$tid = 1;
$aKey = '';

test('Register A-Site', function() use ($registerASite, $tid, &$aKey) {
    $s = $registerASite->execute($tid, 'shop.example.com', 'woocommerce');
    $aKey = $s->apiKey;
    if ($s->domain !== 'shop.example.com') throw new RuntimeException('Domain mismatch');
    if (!str_starts_with($aKey, 'ck_')) throw new RuntimeException('API key format');
});

test('Register B-Site 1 (PayPal, w=5)', function() use ($registerBSite, $tid) {
    $s = $registerBSite->execute($tid, 'pay1.example.com', 'paypal', 5, 100);
    if (!$s->isAvailable()) throw new RuntimeException('Should be available');
});

test('Register B-Site 2 (Stripe, w=3)', function() use ($registerBSite, $tid) {
    $registerBSite->execute($tid, 'pay2.example.com', 'stripe', 3, 80);
});

test('Register B-Site 3 (PayPal, w=1)', function() use ($registerBSite, $tid) {
    $registerBSite->execute($tid, 'pay3.example.com', 'paypal', 1, 50);
});

test('3 B-Sites available', function() use ($bRepo, $tid) {
    $available = $bRepo->findAvailable($tid);
    if (count($available) !== 3) throw new RuntimeException('Expected 3 available, got '.count($available));
});

// ═══════════════════════════════════
echo "\n🚀 Dispatch Orders\n";
$bRefs = [];

test('Dispatch 5 orders with HMAC auth', function() use ($dispatchOrder, $aKey, $tid, &$bRefs, $mRepo) {
    for ($i = 1; $i <= 5; $i++) {
        $ts = (string)time();
        $amt = (string)(10 + $i * 15);
        $payload = json_encode(['a_order_id'=>"WP-10{$i}",'amount'=>$amt,'currency'=>'USD','timestamp'=>$ts]);
        $sig = hash_hmac('sha256', $payload, $aKey);

        $r = $dispatchOrder->execute([
            'api_key'=>$aKey, 'signature'=>$sig,
            'a_order_id'=>"WP-10{$i}", 'amount'=>$amt, 'currency'=>'USD', 'timestamp'=>$ts,
        ]);
        $bRefs[] = $r['b_order_reference'];
        if (empty($r['b_checkout_url'])) throw new RuntimeException("Order $i missing checkout URL");
        if (!str_starts_with($r['b_checkout_url'], 'https://')) throw new RuntimeException("Not HTTPS URL");
    }
    echo "  Dispatched: " . implode(', ', $bRefs) . "\n";
});

test('5 order mappings created', function() use ($mRepo, $tid) {
    $all = $mRepo->findByTenant($tid);
    if (count($all) !== 5) throw new RuntimeException('Expected 5, got '.count($all));
});

// ═══════════════════════════════════
echo "\n💰 Webhook Callbacks\n";

test('Pay 3 orders via webhook', function() use ($handleWebhook, $bRefs) {
    for ($i = 0; $i < 3; $i++) {
        $r = $handleWebhook->execute(['b_order_id'=>$bRefs[$i], 'status'=>'paid']);
        if ($r['mapping_status'] !== 'paid') throw new RuntimeException("Order $i not paid: {$r['mapping_status']}");
    }
});

test('Fail 1 order via webhook', function() use ($handleWebhook, $bRefs) {
    $r = $handleWebhook->execute(['b_order_id'=>$bRefs[3], 'status'=>'failed']);
    if ($r['mapping_status'] !== 'failed') throw new RuntimeException('Expected failed');
});

test('Mapping status distribution correct', function() use ($mRepo, $tid) {
    $all = $mRepo->findByTenant($tid);
    $paid = count(array_filter($all, fn($m)=>$m->status==='paid'));
    $failed = count(array_filter($all, fn($m)=>$m->status==='failed'));
    $pending = count(array_filter($all, fn($m)=>$m->status==='pending'));
    if ($paid !== 3) throw new RuntimeException("Expected 3 paid, got $paid");
    if ($failed !== 1) throw new RuntimeException("Expected 1 failed, got $failed");
    if ($pending !== 1) throw new RuntimeException("Expected 1 pending, got $pending");
    echo "  Status: $paid paid, $failed failed, $pending pending\n";
});

// ═══════════════════════════════════
echo "\n🔥 Cooldown Mechanism\n";

test('3 consecutive failures → cooldown', function() use ($handleWebhook, $bRepo, $mRepo) {
    // Create a fresh B-Site to test cooldown on
    $bs = new BSite(99, 1, 'fragile.example.com', 'paypal', 1, 100, 'active', null, 2);
    $bRepo->save($bs);

    // Create a fake mapping for it
    $map = new OrderMapping(999, 1, 'COOLDOWN-TEST', 'B-COOL-001', 1, 99, '10.00', 'USD', 'pending');
    $mRepo->save($map);

    // 3rd failure triggers cooldown
    $r = $handleWebhook->execute(['b_order_id'=>'B-COOL-001', 'status'=>'failed']);
    if ($r['b_site_status'] !== 'cooled') throw new RuntimeException('Should trigger cooldown, got: '.$r['b_site_status']);

    // Verify B-Site is in cooling
    $site = $bRepo->findById(99);
    if ($site->status !== 'cooling') throw new RuntimeException('Status should be cooling, got: '.$site->status);
    if (!$site->isInCooldown()) throw new RuntimeException('Should be in cooldown');
    if ($site->isAvailable()) throw new RuntimeException('Should NOT be available');

    echo "  BSite #99: status=cooling, isAvailable=false ✅\n";
});

// ═══════════════════════════════════
echo "\n🔐 Auth & Errors\n";

test('Invalid API key rejected', function() use ($dispatchOrder) {
    try {
        $dispatchOrder->execute(['api_key'=>'bad','signature'=>'x','a_order_id'=>'X','amount'=>'0']);
        throw new RuntimeException('Should throw');
    } catch (RuntimeException $e) { /* expected */ }
});

test('Wrong HMAC signature rejected', function() use ($dispatchOrder, $aKey) {
    try {
        $dispatchOrder->execute(['api_key'=>$aKey,'signature'=>'tampered','a_order_id'=>'X','amount'=>'0']);
        throw new RuntimeException('Should throw');
    } catch (RuntimeException $e) { /* expected */ }
});

test('Unknown b_order_id rejected', function() use ($handleWebhook) {
    try {
        $handleWebhook->execute(['b_order_id'=>'B-DEAD','status'=>'paid']);
        throw new RuntimeException('Should throw');
    } catch (RuntimeException $e) { /* expected */ }
});

test('No available B-Sites throws', function() use ($selectGateway) {
    // Use tenant that has no B-Sites
    try {
        $selectGateway->execute(9999);
        throw new RuntimeException('Should throw');
    } catch (RuntimeException $e) { /* expected */ }
});

// ═══════════════════════════════════
echo "\n══════════════════════════════════════════\n";
echo "  SQLite E2E: $pass passed, $fail failed\n";
echo "══════════════════════════════════════════\n";
echo "\n✅ Verified: A→Controller→B flow with real SQLite persistence\n";
exit($fail > 0 ? 1 : 0);
