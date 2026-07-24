<?php
/**
 * PaymentRouter bootstrap — Hooks 注册 + 路由注册
 *
 * 不修改任何现有模块，通过 Hook 总线挂载。
 */
declare(strict_types=1);

use Converge\Core\Hook\Hooks;
use Converge\Foundation\Database\db;
use Converge\Modules\PaymentRouter\Application\DispatchOrderUseCase;
use Converge\Modules\PaymentRouter\Application\GetRoutingDashboardUseCase;
use Converge\Modules\PaymentRouter\Application\HandlePaymentWebhookUseCase;
use Converge\Modules\PaymentRouter\Application\HealthCheckUseCase;
use Converge\Modules\PaymentRouter\Application\ListOrderMappingsUseCase;
use Converge\Modules\PaymentRouter\Application\RegisterASiteUseCase;
use Converge\Modules\PaymentRouter\Application\RegisterBSiteUseCase;
use Converge\Modules\PaymentRouter\Application\SelectGatewayUseCase;
use Converge\Modules\PaymentRouter\Controller\PaymentRouterController;
use Converge\Modules\PaymentRouter\Infrastructure\MysqlASiteRepository;
use Converge\Modules\PaymentRouter\Infrastructure\MysqlBSiteRepository;
use Converge\Modules\PaymentRouter\Infrastructure\MysqlOrderMappingRepository;
use Converge\Modules\PaymentRouter\Infrastructure\PaymentGatewayAdapter;

// ── 依赖组装（手动 DI） ──

$db = db();
$secretKey = $_ENV['APP_SECRET'] ?? 'change-me';

$aSiteRepo = new MysqlASiteRepository($db);
$bSiteRepo = new MysqlBSiteRepository($db);
$mappingRepo = new MysqlOrderMappingRepository($db);
$gateway = new PaymentGatewayAdapter($secretKey);
$selectGateway = new SelectGatewayUseCase($bSiteRepo);
$dispatchOrder = new DispatchOrderUseCase($aSiteRepo, $bSiteRepo, $mappingRepo, $selectGateway, $gateway);
$handleWebhook = new HandlePaymentWebhookUseCase($mappingRepo, $bSiteRepo);
$registerASite = new RegisterASiteUseCase($aSiteRepo);
$registerBSite = new RegisterBSiteUseCase($bSiteRepo);
$healthCheck = new HealthCheckUseCase($bSiteRepo);
$listMappings = new ListOrderMappingsUseCase($mappingRepo);
$dashboard = new GetRoutingDashboardUseCase($db);

$controller = new PaymentRouterController(
    $db, $aSiteRepo, $bSiteRepo, $mappingRepo,
    $dispatchOrder, $handleWebhook, $registerASite, $registerBSite,
    $healthCheck, $listMappings, $dashboard
);

// ── 1. 注册侧边栏菜单 ──

Hooks::addFilter('ui.dock.panels', function (array $panels) use ($controller): array {
    $panels['payment-router'] = [
        'title' => __('nav.payment_router'),
        'icon'  => '💳',
        'order' => 45,
        'items' => [
            ['📊', __('nav.router_dashboard'), 'index.php?page=payment-router-dashboard', 'payment-router-dashboard'],
            ['🌐', __('nav.a_sites'), 'index.php?page=payment-router-a-sites', 'payment-router-a-sites'],
            ['🏪', __('nav.b_sites'), 'index.php?page=payment-router-b-sites', 'payment-router-b-sites'],
            ['📋', __('nav.order_mappings'), 'index.php?page=payment-router-mappings', 'payment-router-mappings'],
            ['⚙️', __('nav.routing_strategy'), 'index.php?page=payment-router-strategy', 'payment-router-strategy'],
        ],
    ];
    return $panels;
});

// ── 2. 注册 REST API 路由 ──

Hooks::addAction('router.register', function ($router) use ($controller): void {
    // 外部 API（API Key 认证应由中间件处理）
    $router->post('/api/payment-router/dispatch', [$controller, 'dispatch']);
    $router->post('/api/payment-router/webhook', [$controller, 'webhook']);

    // 管理 API
    $router->get('/api/payment-router/dashboard', [$controller, 'dashboard']);
    $router->get('/api/payment-router/a-sites', [$controller, 'listASites']);
    $router->post('/api/payment-router/a-sites', [$controller, 'createASite']);
    $router->get('/api/payment-router/b-sites', [$controller, 'listBSites']);
    $router->post('/api/payment-router/b-sites', [$controller, 'createBSite']);
    $router->get('/api/payment-router/mappings', [$controller, 'mappings']);
    $router->post('/api/payment-router/health-check', [$controller, 'triggerHealthCheck']);
});

// ── 3. 注册定时健康检查 ──

Hooks::addAction('cron.hourly', function () use ($healthCheck): void {
    $healthCheck->execute(0); // tenant_id=0 for self-hosted
});
