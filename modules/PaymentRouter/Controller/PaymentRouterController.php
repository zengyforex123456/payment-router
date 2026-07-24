<?php
/**
 * PaymentRouterController — AB 轮询支付中控 REST API
 *
 * ≤15 行/方法，所有业务逻辑委托给 UseCase。
 * 认证: 外部 API 用 API Key + HMAC；管理 API 用 Session。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Controller;

use Converge\Contracts\DatabaseInterface;
use Converge\Foundation\System\TenantScope;
use Converge\Modules\PaymentRouter\Application\DispatchOrderUseCase;
use Converge\Modules\PaymentRouter\Application\GetRoutingDashboardUseCase;
use Converge\Modules\PaymentRouter\Application\HandlePaymentWebhookUseCase;
use Converge\Modules\PaymentRouter\Application\HealthCheckUseCase;
use Converge\Modules\PaymentRouter\Application\ListOrderMappingsUseCase;
use Converge\Modules\PaymentRouter\Application\RegisterASiteUseCase;
use Converge\Modules\PaymentRouter\Application\RegisterBSiteUseCase;
use Converge\Modules\PaymentRouter\Domain\ASiteRepositoryInterface;
use Converge\Modules\PaymentRouter\Domain\BSiteRepositoryInterface;
use Converge\Modules\PaymentRouter\Domain\OrderMappingRepositoryInterface;

final class PaymentRouterController
{
    private DatabaseInterface $db;
    private ASiteRepositoryInterface $aSiteRepo;
    private BSiteRepositoryInterface $bSiteRepo;
    private OrderMappingRepositoryInterface $mappingRepo;
    private DispatchOrderUseCase $dispatchOrder;
    private HandlePaymentWebhookUseCase $handleWebhook;
    private RegisterASiteUseCase $registerASite;
    private RegisterBSiteUseCase $registerBSite;
    private HealthCheckUseCase $healthCheck;
    private ListOrderMappingsUseCase $listMappings;
    private GetRoutingDashboardUseCase $dashboard;

    public function __construct(
        DatabaseInterface $db,
        ASiteRepositoryInterface $aSiteRepo,
        BSiteRepositoryInterface $bSiteRepo,
        OrderMappingRepositoryInterface $mappingRepo,
        DispatchOrderUseCase $dispatchOrder,
        HandlePaymentWebhookUseCase $handleWebhook,
        RegisterASiteUseCase $registerASite,
        RegisterBSiteUseCase $registerBSite,
        HealthCheckUseCase $healthCheck,
        ListOrderMappingsUseCase $listMappings,
        GetRoutingDashboardUseCase $dashboard,
    ) {
        $this->db = $db;
        $this->aSiteRepo = $aSiteRepo;
        $this->bSiteRepo = $bSiteRepo;
        $this->mappingRepo = $mappingRepo;
        $this->dispatchOrder = $dispatchOrder;
        $this->handleWebhook = $handleWebhook;
        $this->registerASite = $registerASite;
        $this->registerBSite = $registerBSite;
        $this->healthCheck = $healthCheck;
        $this->listMappings = $listMappings;
        $this->dashboard = $dashboard;
    }

    // ── 外部 API ──

    /** POST /api/payment-router/dispatch — A 站订单分发 */
    public function dispatch(): void { $this->json($this->dispatchOrder->execute($_POST)); }

    /** POST /api/payment-router/webhook — B 站支付回调 */
    public function webhook(): void { $this->json($this->handleWebhook->execute($_POST)); }

    // ── 管理 API ──

    /** GET /api/payment-router/dashboard — 仪表盘数据 */
    public function dashboard(): void { $this->json($this->dashboard->execute(TenantScope::id())); }

    /** GET /api/payment-router/a-sites — 列出 A 站 */
    public function listASites(): void { $this->json($this->aSiteRepo->findByTenant(TenantScope::id())); }

    /** POST /api/payment-router/a-sites — 注册 A 站 */
    public function createASite(): void { $this->json($this->registerASite->execute(TenantScope::id(), $_POST['domain'] ?? '', $_POST['platform'] ?? 'woocommerce')); }

    /** GET /api/payment-router/b-sites — 列出 B 站 */
    public function listBSites(): void { $this->json($this->bSiteRepo->findByTenant(TenantScope::id())); }

    /** POST /api/payment-router/b-sites — 注册 B 站 */
    public function createBSite(): void {
        $this->json($this->registerBSite->execute(TenantScope::id(), $_POST['domain'] ?? '', $_POST['payment_gateway'] ?? 'paypal', (int)($_POST['weight'] ?? 1), (int)($_POST['max_daily_orders'] ?? 50)));
    }

    /** GET /api/payment-router/mappings — 查询订单映射 */
    public function mappings(): void { $this->json($this->listMappings->execute(TenantScope::id())); }

    /** POST /api/payment-router/health-check — 触发健康检查 */
    public function triggerHealthCheck(): void { $this->json($this->healthCheck->execute(TenantScope::id())); }

    /** 输出 JSON 响应 */
    private function json(mixed $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
    }
}
