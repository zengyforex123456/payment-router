<?php
/**
 * PaymentRouter — Standalone HTTP API Server
 *
 * 不依赖 Converge 完整内核。惰性数据库连接。
 * 启动: DB_HOST=127.0.0.1 DB_NAME=payment_router DB_USER=root DB_PASSWORD="" \
 *       php -S 0.0.0.0:8080 -t docker/payment-router docker/payment-router/index.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// ── 国际化 ──
require_once __DIR__ . '/../../modules/PaymentRouter/I18n/Lang.php';
\Converge\Modules\PaymentRouter\I18n\Lang::init($_GET['lang'] ?? null);

// ── 自动加载 ──
spl_autoload_register(function (string $class): void {
    $prefix = 'Converge\\Modules\\PaymentRouter\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = __DIR__ . '/../../modules/PaymentRouter/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require_once $file;
});

// ── 桩文件 ──
require_once __DIR__ . '/stubs/DatabaseInterface.php';
require_once __DIR__ . '/stubs/TenantScope.php';

// ── 全局服务容器 (惰性初始化) ──
$container = [];
function svc(string $name): object {
    global $container;
    if (isset($container[$name])) return $container[$name];

    if ($name === 'db') {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('DB_PORT') ?: 3306);
        $dbName = getenv('DB_NAME') ?: 'payment_router';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASSWORD') ?: '';
        $mysqli = new mysqli($host, $user, $pass, $dbName, $port);
        if ($mysqli->connect_error) throw new \RuntimeException("DB: {$mysqli->connect_error}");
        $mysqli->set_charset('utf8mb4');
        return $container['db'] = new class($mysqli) implements \Converge\Contracts\DatabaseInterface {
            private mysqli $db;
            public function __construct(mysqli $db) { $this->db = $db; }
            public function query(string $sql): mixed {
                $r = $this->db->query($sql);
                if ($r === false) throw new \RuntimeException("SQL: {$this->db->error}");
                return $r;
            }
            public function prepare(string $sql): mixed {
                $s = $this->db->prepare($sql);
                if ($s === false) throw new \RuntimeException("Prepare: {$this->db->error}");
                return $s;
            }
            public function escape(string $v): string { return $this->db->real_escape_string($v); }
            public function lastInsertId(): int { return (int)$this->db->insert_id; }
            public function affectedRows(): int { return $this->db->affected_rows; }
            public function raw(): mixed { return $this->db; }
        };
    }

    // 组装 UseCase 依赖链
    $secret = getenv('APP_SECRET') ?: 'change-me';
    $aRepo = $container['aRepo'] ??= new \Converge\Modules\PaymentRouter\Infrastructure\MysqlASiteRepository(svc('db'));
    $bRepo = $container['bRepo'] ??= new \Converge\Modules\PaymentRouter\Infrastructure\MysqlBSiteRepository(svc('db'));
    $mRepo = $container['mRepo'] ??= new \Converge\Modules\PaymentRouter\Infrastructure\MysqlOrderMappingRepository(svc('db'));
    $gw    = $container['gw']    ??= new \Converge\Modules\PaymentRouter\Infrastructure\PaymentGatewayAdapter($secret);
    $sg    = $container['sg']    ??= new \Converge\Modules\PaymentRouter\Application\SelectGatewayUseCase($bRepo);
    $do    = $container['do']    ??= new \Converge\Modules\PaymentRouter\Application\DispatchOrderUseCase($aRepo, $bRepo, $mRepo, $sg, $gw);
    $hw    = $container['hw']    ??= new \Converge\Modules\PaymentRouter\Application\HandlePaymentWebhookUseCase($mRepo, $bRepo, svc('db'));
    $ra    = $container['ra']    ??= new \Converge\Modules\PaymentRouter\Application\RegisterASiteUseCase($aRepo);
    $rb    = $container['rb']    ??= new \Converge\Modules\PaymentRouter\Application\RegisterBSiteUseCase($bRepo);
    $hc    = $container['hc']    ??= new \Converge\Modules\PaymentRouter\Application\HealthCheckUseCase($bRepo);
    $lm    = $container['lm']    ??= new \Converge\Modules\PaymentRouter\Application\ListOrderMappingsUseCase($mRepo);
    $dash  = $container['dash']  ??= new \Converge\Modules\PaymentRouter\Application\GetRoutingDashboardUseCase(svc('db'));
    $strat = $container['strategy'] ??= new \Converge\Modules\PaymentRouter\Application\ConfigureStrategyUseCase(svc('db'));
    $usage = $container['usage'] ??= new \Converge\Modules\PaymentRouter\Application\GetTenantUsageUseCase(svc('db'));
    $bulk   = $container['bulk']   ??= new \Converge\Modules\PaymentRouter\Application\BulkImportUseCase(svc('db'));
    $admin  = $container['admin']  ??= new \Converge\Modules\PaymentRouter\Application\SuperAdminDashboardUseCase(svc('db'));
    $alerts = $container['alerts'] ??= new \Converge\Modules\PaymentRouter\Application\AlertNotificationUseCase(svc('db'), [
        'telegram_bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: '',
        'telegram_chat_id'   => getenv('TELEGRAM_CHAT_ID') ?: '',
        'slack_webhook_url'  => getenv('SLACK_WEBHOOK_URL') ?: '',
        'alert_email'        => getenv('ALERT_EMAIL') ?: '',
        'alert_webhook_url'  => getenv('ALERT_WEBHOOK_URL') ?: '',
    ]);
    $psync  = $container['psync']  ??= new \Converge\Modules\PaymentRouter\Application\ProductSyncUseCase(svc('db'));
    $recon  = $container['recon']  ??= new \Converge\Modules\PaymentRouter\Application\ReconciliationUseCase(svc('db'));
    $gateK  = $container['gate']   ??= new \Converge\Modules\PaymentRouter\Application\FeatureGateUseCase(svc('db'));
    $lic    = $container['lic']    ??= new \Converge\Modules\PaymentRouter\Application\LicenseManagerUseCase(svc('db'), getenv('APP_SECRET') ?: 'change-me');
    $trial  = $container['trial']  ??= new \Converge\Modules\PaymentRouter\Application\TrialManagerUseCase(svc('db'), 14);
    $auth   = $container['auth']   ??= new \Converge\Modules\PaymentRouter\Application\AuthUseCase(svc('db'));
    $bill   = $container['billing'] ??= new \Converge\Modules\PaymentRouter\Application\BillingManagerUseCase(svc('db'), [
        'stripe_secret_key'      => getenv('STRIPE_SECRET_KEY') ?: '',
        'stripe_webhook_secret'  => getenv('STRIPE_WEBHOOK_SECRET') ?: '',
        'base_url'               => getenv('APP_URL') ?: 'http://localhost:8080',
        'app_secret'             => getenv('APP_SECRET') ?: 'change-me',
    ]);

    return $container[$name] ?? throw new \RuntimeException("Unknown service: $name");
}

// ── 路由 ──
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
// Merge GET params for read-only endpoints
if ($method === 'GET') { $body = array_merge($_GET, $body); }

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Signature, X-Api-Key');
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// ── Embed JS (SaaS 核心交付入口) ──
if ($path === '/embed.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=300, s-maxage=600');
    header('Access-Control-Allow-Origin: *');
    try {
        require_once __DIR__ . '/../../modules/PaymentRouter/Cloak/Application/EmbedJsUseCase.php';
        $embed = new \Converge\Modules\PaymentRouter\Cloak\Application\EmbedJsUseCase(svc('db'));
        echo $embed->render($_GET);
    } catch (\Throwable $e) {
        // DB 不可用 → 返回静态社区版
        $safe = json_encode($_GET['safe'] ?? '');
        $real = json_encode($_GET['real'] ?? '');
        echo "(function(){var s={$safe},r={$real};if(s&&s!==location.href)location.replace(s);console.warn('[Cloak] DB unavailable, running in community mode');})();";
    }
    exit;
}

// Serve static pages
$staticPages = ['/' => 'index.html', '/login' => 'login.html', '/register' => 'register.html',
    '/app' => 'app.html', '/admin' => 'admin.html', '/pricing' => 'index.html', '/docs' => 'docs.html',
    '/checkout' => 'checkout.html'];
foreach ($staticPages as $p => $f) {
    if ($path === $p) { header('Content-Type: text/html; charset=utf-8'); readfile(__DIR__ . '/../../public/' . $f); exit; }
}

// Static files — PHP built-in server serves them directly
if (preg_match('/\.(css|js|png|ico|svg|woff2?)$/', $path)) {
    return false;
}

// ── Docs: serve .md files as styled HTML ──
if (preg_match('#^/docs/(\w[\w-]*)$#', $path, $m)) {
    $slug = $m[1];
    $map = ['quickstart'=>'DELIVERY_PLAN','deploy'=>'DEPLOY','api'=>'API','user-guide'=>'USER_GUIDE',
            'business'=>'BUSINESS_MODEL','delivery'=>'DELIVERY_PLAN','cloak'=>'DELIVERY_PLAN','wordpress'=>'USER_GUIDE'];
    $file = __DIR__ . '/../../docs/' . ($map[$slug] ?? strtoupper($slug)) . '.md';
    if (file_exists($file)) {
        $md = file_get_contents($file);
        $title = preg_match('/^#\s+(.+)/m', $md, $tm) ? $tm[1] : $slug;
        $html = '<!DOCTYPE html><html lang="zh"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>'.$title.' — PaymentRouter</title>
<style>:root{--bg:#0f172a;--surface:#1e293b;--border:#334155;--text:#e2e8f0;--muted:#94a3b8;--primary:#3b82f6;--radius:8px}
*{box-sizing:border-box;margin:0;padding:0}body{font-family:system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.7;padding:40px;max-width:800px;margin:0 auto}
h1{font-size:28px;margin-bottom:24px;border-bottom:1px solid var(--border);padding-bottom:16px}
h2{font-size:20px;margin:32px 0 12px;color:var(--primary)}h3{font-size:16px;margin:20px 0 8px}
p{margin:8px 0;color:var(--text)}code{background:var(--surface);padding:2px 6px;border-radius:4px;font-size:13px;border:1px solid var(--border)}
pre{background:var(--surface);padding:16px;border-radius:var(--radius);overflow-x:auto;font-size:13px;margin:12px 0;border:1px solid var(--border)}
pre code{background:none;border:none;padding:0}table{width:100%;border-collapse:collapse;margin:16px 0}
th,td{text-align:left;padding:8px 12px;border:1px solid var(--border);font-size:13px}th{background:var(--surface)}
a{color:var(--primary)}ul,ol{margin:8px 0;padding-left:24px}li{margin:4px 0;font-size:14px}
.back{margin-top:40px;font-size:13px;color:var(--muted)}.back a{color:var(--primary)}</style></head><body>
<div class="back"><a href="/docs">← 文档首页</a> · <a href="/">返回首页</a></div>
';
        $html .= '<div class="content">' . preg_replace_callback('/```(\w*)\n(.*?)```/s', function($b){return '<pre><code>'.htmlspecialchars($b[2]).'</code></pre>';},
            preg_replace_callback('/`([^`]+)`/', function($b){return '<code>'.htmlspecialchars($b[1]).'</code>';},
            preg_replace('/^### (.+)/m','<h3>$1</h3>',
            preg_replace('/^## (.+)/m','<h2>$1</h2>',
            preg_replace('/^# (.+)/m','<h1>$1</h1>',
            preg_replace('/\|(.+)\|/','<tr><td>'.str_replace('|','</td><td>','$1').'</td></tr>',
            preg_replace('/^- (.+)/m','<li>$1</li>',
            preg_replace('/^(\d+)\. (.+)/m','<li>$2</li>',
            preg_replace('/\n\n/','</p><p>',
            '<p>'.htmlspecialchars($md).'</p>'
        ))))))))). '</div></body></html>';
        echo $html; exit;
    }
    header('Location: /docs'); exit;
}

// Health — 无需 DB
if ($path === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'service' => 'payment-router', 'time' => date('c')]);
    exit;
}

// 所有 API 请求惰性连接 DB
try {
    $result = match (true) {
        // ── Cloak 斗篷 ──
        // JS Challenge 页面（爬虫防护）
        // 行为追踪 Beacon
        $method === 'POST' && $path === '/cloak/beacon'
            => (function() use ($body) {
                require_once __DIR__ . '/../../modules/PaymentRouter/Cloak/Application/BehaviorAnalyzer.php';
                $analyzer = new \Converge\Modules\PaymentRouter\Cloak\Application\BehaviorAnalyzer(svc('db'));
                return $analyzer->analyze($body);
            })(),
        $method === 'GET' && $path === '/cloak/challenge'
            => (function() use ($body) {
                require_once __DIR__ . '/../../modules/PaymentRouter/Cloak/Infrastructure/BrowserFingerprint.php';
                header('Content-Type: text/html; charset=utf-8');
                echo \Converge\Modules\PaymentRouter\Cloak\Infrastructure\BrowserFingerprint::renderChallenge(
                    $body['real_url'] ?? '/app',
                    $body['safe_url'] ?? '/'
                );
                exit;
            })(),
        $method === 'GET' && $path === '/cloak'
            => (function() use ($body) {
                require_once __DIR__ . '/../../modules/PaymentRouter/Cloak/Domain/CloakVisitor.php';
                require_once __DIR__ . '/../../modules/PaymentRouter/Cloak/Domain/CloakRule.php';
                require_once __DIR__ . '/../../modules/PaymentRouter/Cloak/Domain/CloakDecision.php';
                require_once __DIR__ . '/../../modules/PaymentRouter/Cloak/Application/IpIntelService.php';
                require_once __DIR__ . '/../../modules/PaymentRouter/Cloak/Application/EvaluateCloakUseCase.php';
                $rules = [];
                if (isset($container['db'])) {
                    require_once __DIR__ . '/../../modules/PaymentRouter/Cloak/Infrastructure/MysqlCloakRuleRepository.php';
                    $repo = new \Converge\Modules\PaymentRouter\Cloak\Infrastructure\MysqlCloakRuleRepository(svc('db'));
                    $rules = $repo->findAllEnabled();
                }
                $v = \Converge\Modules\PaymentRouter\Cloak\Domain\CloakVisitor::fromServer($_SERVER);
                $engine = new \Converge\Modules\PaymentRouter\Cloak\Application\EvaluateCloakUseCase($rules);
                return $engine->execute($v, $body['safe_url'] ?? 'https://safe.example.com', $body['real_url'] ?? 'https://real.example.com');
            })(),

        // ── 用户认证 ──
        $method === 'POST' && $path === '/api/auth/register'
            => svc('auth')->register($body['email'] ?? '', $body['password'] ?? ''),
        $method === 'POST' && $path === '/api/auth/login'
            => svc('auth')->login($body['email'] ?? '', $body['password'] ?? ''),
        $method === 'GET' && $path === '/api/auth/profile'
            => svc('auth')->profile((int)($body['user_id'] ?? 0)) ?? ['error' => 'Not found'],

        // ── Payment Router API ──
        $method === 'POST' && $path === '/api/payment-router/dispatch'
            => svc('do')->execute($body),
        $method === 'POST' && $path === '/api/payment-router/webhook'
            => svc('hw')->execute($body),

        $method === 'GET' && $path === '/api/payment-router/dashboard'
            => svc('dash')->execute((int)($body['tenant_id'] ?? 0)),
        $method === 'GET' && $path === '/api/payment-router/a-sites'
            => array_map(fn($s) => ['id'=>$s->id,'domain'=>$s->domain,'platform'=>$s->platform,'apiKey'=>$s->apiKey,'status'=>$s->status], svc('aRepo')->findByTenant((int)($body['tenant_id'] ?? 0))),
        $method === 'POST' && $path === '/api/payment-router/a-sites'
            => (function() use ($body) { $s = svc('ra')->execute((int)($body['tenant_id'] ?? 0), $body['domain'] ?? '', $body['platform'] ?? 'woocommerce'); return ['id'=>$s->id,'domain'=>$s->domain,'apiKey'=>$s->apiKey,'status'=>$s->status]; })(),
        $method === 'DELETE' && preg_match('#^/api/payment-router/a-sites/(\d+)$#', $path, $m)
            => (function() use ($m) { svc('aRepo')->delete((int)$m[1]); return ['deleted'=>true]; })(),
        $method === 'GET' && $path === '/api/payment-router/b-sites'
            => array_map(fn($s) => ['id'=>$s->id,'domain'=>$s->domain,'gateway'=>$s->paymentGateway,'weight'=>$s->weight,'maxDaily'=>$s->maxDailyOrders,'status'=>$s->status,'todayOrders'=>$s->dailyOrderCount,'failures'=>$s->consecutiveFailures], svc('bRepo')->findByTenant((int)($body['tenant_id'] ?? 0))),
        $method === 'GET' && $path === '/api/payment-router/mappings'
            => svc('lm')->execute((int)($body['tenant_id'] ?? 0)),
        $method === 'GET' && $path === '/api/payment-router/usage'
            => svc('usage')->execute((int)($body['tenant_id'] ?? 0)),
        $method === 'GET' && $path === '/api/payment-router/strategy'
            => svc('strategy')->get((int)($body['tenant_id'] ?? 0)),
        $method === 'POST' && $path === '/api/payment-router/strategy'
            => svc('strategy')->applyPreset((int)($body['tenant_id'] ?? 0), $body['preset'] ?? 'balanced'),
        $method === 'PATCH' && $path === '/api/payment-router/strategy'
            => svc('strategy')->custom((int)($body['tenant_id'] ?? 0), $body),
        $method === 'GET' && $path === '/api/payment-router/config/export'
            => svc('strategy')->export((int)($body['tenant_id'] ?? 0)),
        $method === 'POST' && $path === '/api/payment-router/config/import'
            => svc('strategy')->import((int)($body['tenant_id'] ?? 0), $body),
        $method === 'POST' && $path === '/api/payment-router/bulk/import/a-sites'
            => svc('bulk')->importASites((int)($body['tenant_id'] ?? 0), $body['sites'] ?? []),
        $method === 'POST' && $path === '/api/payment-router/bulk/import/b-sites'
            => svc('bulk')->importBSites((int)($body['tenant_id'] ?? 0), $body['sites'] ?? []),
        $method === 'POST' && $path === '/api/payment-router/routing-script/validate'
            => \Converge\Modules\PaymentRouter\Domain\RoutingScript::validate($body['rules'] ?? []),
        $method === 'POST' && $path === '/api/payment-router/routing-script/evaluate'
            => (new \Converge\Modules\PaymentRouter\Domain\RoutingScript($body['rules'] ?? []))->evaluate($body['context'] ?? []),
        $method === 'GET' && $path === '/api/payment-router/oem'
            => (new \Converge\Modules\PaymentRouter\Domain\OemConfig())->toArray(),
        $method === 'POST' && preg_match('#^/api/payment-router/b-sites/(\d+)/recover$#', $path, $m)
            => (function() use ($m) { $bs = svc('bRepo')->findById((int)$m[1]); if(!$bs) throw new RuntimeException('B站不存在'); svc('bRepo')->save($bs->recover()); return ['recovered'=>true,'id'=>(int)$m[1],'status'=>'active']; })(),
        $method === 'POST' && $path === '/api/payment-router/alerts/check'
            => svc('alerts')->checkAndAlert((int)($body['tenant_id'] ?? 0)),
        $method === 'POST' && $path === '/api/payment-router/alerts/test'
            => (function() use ($body) {
                $cfg = [
                    'telegram_bot_token' => $body['telegram_bot_token'] ?? getenv('TELEGRAM_BOT_TOKEN') ?: '',
                    'telegram_chat_id'   => $body['telegram_chat_id']   ?? getenv('TELEGRAM_CHAT_ID') ?: '',
                    'slack_webhook_url'  => $body['slack_webhook_url']  ?? getenv('SLACK_WEBHOOK_URL') ?: '',
                ];
                $a = new \Converge\Modules\PaymentRouter\Application\AlertNotificationUseCase(svc('db'), $cfg);
                return $a->send($body['level'] ?? 'info', $body['title'] ?? 'Test', $body['message'] ?? 'Test', $body['channels'] ?? []);
            })(),
        $method === 'POST' && $path === '/api/payment-router/product-sync'
            => svc('psync')->push((int)($body['tenant_id'] ?? 0), $body['product'] ?? []),
        $method === 'GET' && $path === '/api/payment-router/product-sync'
            => svc('psync')->history((int)($body['tenant_id'] ?? 0)),
        $method === 'POST' && $path === '/api/payment-router/reconciliation'
            => svc('recon')->execute((int)($body['tenant_id'] ?? 0)),
        $method === 'GET' && $path === '/api/payment-router/dashboard/trends'
            => (function() use ($body) { return svc('dash')->getTrends((int)($body['tenant_id'] ?? 0)); })(),
        // P0-CE: Community limits enforced
        $method === 'POST' && $path === '/api/payment-router/b-sites'
            => (function() use ($body) { $g = svc('gate'); $check = $g->canAddBSite((int)($body['tenant_id'] ?? 0)); if(!$check['allowed']) throw new RuntimeException($check['message']); $s = svc('rb')->execute((int)($body['tenant_id'] ?? 0), $body['domain'] ?? '', $body['payment_gateway'] ?? 'paypal', (int)($body['weight'] ?? 1), (int)($body['max_daily_orders'] ?? 50)); return ['id'=>$s->id,'domain'=>$s->domain,'gateway'=>$s->paymentGateway,'status'=>$s->status]; })(),
        // P0-CE: License management
        $method === 'POST' && $path === '/api/payment-router/license/issue'
            => svc('lic')->issue($body['domain'] ?? '', $body['tier'] ?? 'pro', $body['duration'] ?? '+1 year')->toArray(),
        $method === 'POST' && $path === '/api/payment-router/license/validate'
            => svc('lic')->validate($body['license_key'] ?? '', $body['domain'] ?? ''),
        $method === 'POST' && $path === '/api/payment-router/license/revoke'
            => (function() use ($body) { svc('lic')->revoke($body['license_key'] ?? ''); return ['revoked'=>true]; })(),
        $method === 'GET' && $path === '/api/payment-router/license/list'
            => svc('lic')->listAll(),
        // P0-CE: Feature gate check
        $method === 'GET' && $path === '/api/payment-router/feature-gate'
            => svc('gate')->getPermissions((int)($body['tenant_id'] ?? 0)),
        // P0-CE: Update changelog
        // P1-Monetize: Trial + Upgrade
        $method === 'POST' && $path === '/api/payment-router/trial/start'
            => svc('trial')->startTrial((int)($body['tenant_id'] ?? 0)),
        $method === 'GET' && $path === '/api/payment-router/trial/status'
            => svc('trial')->getTrialStatus((int)($body['tenant_id'] ?? 0)),
        $method === 'POST' && $path === '/api/payment-router/upgrade'
            => svc('trial')->upgrade((int)($body['tenant_id'] ?? 0), $body['tier'] ?? 'starter', $body['license_key'] ?? ''),
        // P2-Billing: Payments
        $method === 'POST' && $path === '/api/payment-router/billing/checkout'
            => svc('billing')->createStripeCheckout((int)($body['tenant_id'] ?? 0), $body['product_id'] ?? '', $body['domain'] ?? ''),
        $method === 'POST' && $path === '/api/payment-router/billing/webhook/stripe'
            => svc('billing')->handleStripeWebhook(file_get_contents('php://input'), $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''),
        $method === 'POST' && $path === '/api/payment-router/billing/crypto/confirm'
            => svc('billing')->confirmCryptoPayment((int)($body['tenant_id'] ?? 0), $body['product_id'] ?? '', $body['domain'] ?? '', $body['tx_hash'] ?? '', $body['network'] ?? 'TRC20'),
        $method === 'GET' && $path === '/api/payment-router/billing/history'
            => svc('billing')->getPaymentHistory((int)($body['tenant_id'] ?? 0)),
        $method === 'GET' && $path === '/api/payment-router/products'
            => \Converge\Modules\PaymentRouter\Application\BillingManagerUseCase::PRODUCTS,
        $method === 'GET' && $path === '/api/payment-router/updates'
            => (function() {
                $stmt = svc('db')->prepare('SELECT version, title, changes, is_security, published_at FROM payment_router_updates ORDER BY published_at DESC LIMIT 20');
                $stmt->execute();
                return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            })(),
        $method === 'GET' && $path === '/api/payment-router/admin/tenants'
            => svc('admin')->execute(),
        $method === 'GET' && $path === '/api/payment-router/presets'
            => array_map(fn($p) => $p->toArray(), \Converge\Modules\PaymentRouter\Domain\StrategyTemplate::presets()),
        $method === 'POST' && $path === '/api/payment-router/health-check'
            => svc('hc')->execute((int)($body['tenant_id'] ?? 0)),

        default => (function() { http_response_code(404); return ['error'=>'Not Found: ' . $_SERVER['REQUEST_METHOD'] . ' ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)]; })()
    };

    if (http_response_code() === false || http_response_code() === 200) {
        http_response_code(200);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);

} catch (\Throwable $e) {
    $msg = $e->getMessage();
    $code = match (true) {
        str_contains($msg, '无效') || str_contains($msg, '签名') => 401,
        str_contains($msg, '未找到') => 404,
        str_contains($msg, '不可用') => 503,
        str_contains($msg, '拒绝') || str_contains($msg, 'connect') || str_contains($msg, 'DB:') || str_contains($msg, 'SQL:') => 503,
        default => 400,
    };
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
}
