<?php
error_reporting(E_ALL);
ini_set("display_errors", "1");

require "/var/www/modules/PaymentRouter/I18n/Lang.php";
require "/var/www/docker/payment-router/stubs/DatabaseInterface.php";
require "/var/www/docker/payment-router/stubs/TenantScope.php";

spl_autoload_register(function (string $class): void {
    $prefix = "Converge\\Modules\\PaymentRouter\\";
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = "/var/www/modules/PaymentRouter/" . str_replace("\\", "/", $relativeClass) . ".php";
    if (file_exists($file)) require_once $file;
});

use Converge\Contracts\DatabaseInterface;

function _db(): DatabaseInterface {
    $h = getenv("DB_HOST") ?: "127.0.0.1";
    $p = (int)(getenv("DB_PORT") ?: 3306);
    $n = getenv("DB_NAME") ?: "payment_router";
    $u = getenv("DB_USER") ?: "root";
    $pw = getenv("DB_PASSWORD") ?: "";
    $m = new mysqli($h, $u, $pw, $n, $p);
    return new class($m) implements DatabaseInterface {
        private mysqli $d;
        public function __construct(mysqli $d) { $this->d = $d; }
        public function query(string $s): mixed { $r = $this->d->query($s); if ($r === false) throw new RuntimeException("SQL: ".$this->d->error); return $r; }
        public function prepare(string $s): mixed { $st = $this->d->prepare($s); if ($st === false) throw new RuntimeException("Prep: ".$this->d->error); return $st; }
        public function escape(string $v): string { return $this->d->real_escape_string($v); }
        public function lastInsertId(): int { return (int)$this->d->insert_id; }
        public function affectedRows(): int { return $this->d->affected_rows; }
        public function raw(): mixed { return $this->d; }
    };
}

$db = _db();
$sec = getenv("APP_SECRET") ?: "change-me";
$aR = new Converge\Modules\PaymentRouter\Infrastructure\MysqlASiteRepository($db);
$bR = new Converge\Modules\PaymentRouter\Infrastructure\MysqlBSiteRepository($db);
$mR = new Converge\Modules\PaymentRouter\Infrastructure\MysqlOrderMappingRepository($db);
$gw = new Converge\Modules\PaymentRouter\Infrastructure\PaymentGatewayAdapter($sec);
$sg = new Converge\Modules\PaymentRouter\Application\SelectGatewayUseCase($bR);
$do = new Converge\Modules\PaymentRouter\Application\DispatchOrderUseCase($aR, $bR, $mR, $sg, $gw);
$au = new Converge\Modules\PaymentRouter\Application\AuthUseCase($db);
$lm = new Converge\Modules\PaymentRouter\Application\LicenseManagerUseCase($db, $sec);
$tm = new Converge\Modules\PaymentRouter\Application\TrialManagerUseCase($db, 14);
$gk = new Converge\Modules\PaymentRouter\Application\FeatureGateUseCase($db);
$ad = new Converge\Modules\PaymentRouter\Application\SuperAdminDashboardUseCase($db);
$bl = new Converge\Modules\PaymentRouter\Application\BillingManagerUseCase($db, ["app_secret"=>$sec,"base_url"=>"http://localhost:8085"]);
$oe = new Converge\Modules\PaymentRouter\Domain\OemConfig();

$P = function($s) { echo "  $s\n"; };
$OK = function() { echo "  [OK]\n"; };

echo "==========================================================\n";
echo "  PAYMENTROUTER AGENCY SIMULATION\n";
echo "==========================================================\n\n";

// 1: Agency registration
echo "1. AGENCY REGISTRATION\n";
echo "------------------------------\n";
try { $r = $au->register("agency@test.com","TestAgency123!"); $P("Registered: user_id={$r["user_id"]} tier={$r["tier"]}"); }
catch (\Throwable $e) { $P("Exists: ".$e->getMessage()); }
$ag = $au->login("agency@test.com","TestAgency123!");
$P("Agency: user_id={$ag["user_id"]} email={$ag["email"]} tier={$ag["tier"]}\n");

// 2: Onboard 3 clients
echo "2. ONBOARD 3 CLIENTS\n";
echo "------------------------------\n";
$cl = [];
foreach(["client1","client2","client3"] as $n) {
    try { $r = $au->register("{$n}@test.com","ClientPass123!"); $cl[$n] = $r; $P("$n: id={$r["user_id"]} tier={$r["tier"]}"); }
    catch (\Throwable $e) { $r = $au->login("{$n}@test.com","ClientPass123!"); $cl[$n] = $r; $P("$n(exists): id={$r["user_id"]} tier={$r["tier"]}"); }
}
echo "\n";

// 3: Create sites + dispatch orders
echo "3. SITES + ORDERS\n";
echo "------------------------------\n";
foreach($cl as $n=>$c) {
    $tid = $c["user_id"];
    $P("[$n] tenant_id=$tid");
    $tm->upgrade($tid,"starter");
    $a = $aR->save(new Converge\Modules\PaymentRouter\Domain\ASite(0,$tid,"{$n}-a.myshop.com","woocommerce"));
    $P("A-site: id={$a->id} key=".substr($a->apiKey,0,12)."...");
    $b1 = $bR->save(new Converge\Modules\PaymentRouter\Domain\BSite(0,$tid,"{$n}-b1.myshop.com","paypal",3,50));
    $b2 = $bR->save(new Converge\Modules\PaymentRouter\Domain\BSite(0,$tid,"{$n}-b2.myshop.com","stripe",1,50));
    $P("B-sites: {$b1->id}(paypal) {$b2->id}(stripe)");
    for($k=1;$k<=3;$k++) {
        try {
            $amt = (string)(99.99+$k);
            $sig = hash_hmac("sha256",json_encode(["a_order_id"=>"ORD-{$tid}-{$k}","amount"=>$amt,"currency"=>"USD","timestamp"=>time()]),$a->apiKey);
            $res = $do->execute(["api_key"=>$a->apiKey,"signature"=>$sig,"a_order_id"=>"ORD-{$tid}-{$k}","amount"=>$amt,"currency"=>"USD","timestamp"=>time()]);
            $P("Order #$k -> {$res["b_order_reference"]} ({$res["b_site_domain"]})");
        } catch(\Throwable $e) { $P("Order #$k FAILED: ".$e->getMessage()); }
    }
    echo "\n";
}

// 4: License issue
echo "4. LICENSE ISSUE\n";
echo "------------------------------\n";
$lic = $lm->issue("client1-premium.com","pro","+1 year");
$P("key={$lic->licenseKey} tier={$lic->tier} expires={$lic->expiresAt}");
echo json_encode($lic->toArray(),JSON_UNESCAPED_UNICODE)."\n\n";

// 5: License validate
echo "5. LICENSE VALIDATE\n";
echo "------------------------------\n";
$v = $lm->validate($lic->licenseKey,"client1-premium.com");
$P("Valid domain: ".json_encode($v));
$v2 = $lm->validate($lic->licenseKey,"evil-site.com");
$P("Wrong domain: ".json_encode($v2));
$lm->revoke($lic->licenseKey);
$v3 = $lm->validate($lic->licenseKey,"client1-premium.com");
$P("After revoke: ".json_encode($v3));
echo "\n";

// 6: Trial -> Upgrade
echo "6. TRIAL -> UPGRADE\n";
echo "------------------------------\n";
$ts = $tm->getTrialStatus($cl["client1"]["user_id"]);
$P("client1 trial: ".json_encode($ts));
try { $r2 = $au->register("trial-user@test.com","TrialPass123!"); $tu = $r2["user_id"]; }
catch(\Throwable $e) { $r2 = $au->login("trial-user@test.com","TrialPass123!"); $tu = $r2["user_id"]; }
$t = $tm->startTrial($tu);
$P("Trial: ".json_encode($t));
$u = $tm->upgrade($tu,"pro","PR-UPGRADE-TRIAL-001");
$P("Upgrade: ".json_encode($u));
echo "\n";

// 7: Billing
echo "7. BILLING\n";
echo "------------------------------\n";
$P("Products: ".json_encode(Converge\Modules\PaymentRouter\Application\BillingManagerUseCase::PRODUCTS));
$co = $bl->createStripeCheckout($cl["client1"]["user_id"],"pro_onetime","client1-premium.com");
$P("Checkout: ".json_encode($co));
$cp = $bl->confirmCryptoPayment($cl["client2"]["user_id"],"enterprise_onetime","client2-enterprise.com","0xdeadbeef","TRC20");
$P("Crypto: ".json_encode($cp));
echo "\n";

// 8: Feature gates
echo "8. FEATURE GATES\n";
echo "------------------------------\n";
$ck = $gk->canAddBSite($ag["user_id"]);
$P("Community(agency) add B-site: ".json_encode($ck));
foreach([["agency@test.com","TestAgency123!","community"],["client1@test.com","ClientPass123!","starter"],["trial-user@test.com","TrialPass123!","pro"]] as list($em,$pw,$tier)) {
    $p = $au->login($em,$pw);
    $perms = $gk->getPermissions($p["user_id"]);
    $P("$tier: A={$perms["max_a_sites"]} B={$perms["max_b_sites"]} dash=".($perms["dashboard"]?"Y":"N")." oem=".($perms["oem"]?"Y":"N")." mt=".($perms["multi_tenant"]?"Y":"N"));
}
echo "\n";

// 9: OEM config
echo "9. OEM CONFIG\n";
echo "------------------------------\n";
$P("Default: brand={$oe->brandName} logo={$oe->logoUrl} hide=".($oe->hidePoweredBy?"Y":"N"));
$oe->brandName = "AgencyCorp Payments";
$oe->logoUrl = "https://agencycorp.com/logo.png";
$oe->primaryColor = "#ff6600";
$oe->hidePoweredBy = true;
$P("Custom: brand={$oe->brandName} color={$oe->primaryColor} hide=".($oe->hidePoweredBy?"Y":"N"));
echo json_encode($oe->toArray(),JSON_UNESCAPED_UNICODE)."\n\n";

// 10: Super-admin
echo "10. SUPER-ADMIN TENANTS\n";
echo "------------------------------\n";
try {
    $oa = $ad->execute();
    $P("Result: ".json_encode($oa));
} catch(\Throwable $e) {
    $P("BUG: ".$e->getMessage()." (compact(\"summary\") but var is \"tenants\")");
    $r = $db->query("SELECT tc.tenant_id,tc.tier, (SELECT COUNT(*) FROM payment_router_a_sites WHERE tenant_id=tc.tenant_id) a, (SELECT COUNT(*) FROM payment_router_b_sites WHERE tenant_id=tc.tenant_id) b FROM payment_router_tenant_config tc ORDER BY tc.tenant_id");
    while($row = $r->fetch_assoc()) $P("tid={$row["tenant_id"]} tier={$row["tier"]} A={$row["a"]} B={$row["b"]}");
}
echo "\n";

// Summary
echo "==========================================================\n";
echo "  SUMMARY\n";
echo "==========================================================\n";
$P("Agency: agency@test.com (user_id={$ag["user_id"]}, tier={$ag["tier"]})");
$P("Clients: ".count($cl)." (client1/2/3@test.com)");
$P("Trial user: trial-user@test.com (upgraded to pro)");
$r = $db->query("SELECT 'pr_users' t,COUNT(*) c FROM payment_router_users UNION SELECT 'pr_a_sites',COUNT(*) FROM payment_router_a_sites UNION SELECT 'pr_b_sites',COUNT(*) FROM payment_router_b_sites UNION SELECT 'pr_orders',COUNT(*) FROM payment_router_order_mappings UNION SELECT 'pr_licenses',COUNT(*) FROM payment_router_licenses UNION SELECT 'pr_payments',COUNT(*) FROM payment_router_payments UNION SELECT 'pr_trials',COUNT(*) FROM payment_router_trials UNION SELECT 'pr_upgrades',COUNT(*) FROM payment_router_upgrade_history");
echo "\nDatabase totals:\n";
while($row = $r->fetch_assoc()) printf("  %-15s %s\n",$row["t"],$row["c"]);

echo "\nMULTI-TENANT ISOLATION:\n";
$P("TenantScope stub always returns 0 — no isolation in standalone mode");
$P("Schema supports tenant_id on all tables");
$P("Isolation requires Converge kernel with proper TenantScope");

echo "\nISSUES FOUND:\n";
echo "------------------------------\n";
$P("1. SuperAdminDashboardUseCase: compact(\"summary\") var mismatch (bug)");
$P("2. TenantScope stub returns 0 always — no practical isolation standalone");
$P("3. DispatchOrder HMAC uses aSite->apiKey, not tenant-level key");
$P("4. License offline cache is file-based — no DB replication");
$P("5. No agency/reseller role — agency and client are same user concept");
$P("6. Tier enforcement in frontend only — back-end gate is soft");
$P("7. AuthUseCase auto-creates trial on register — no opt-out");
$P("8. OEM config is in-memory only — not persisted to DB");
echo "==========================================================\n";