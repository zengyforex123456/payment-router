<?php

declare(strict_types=1);

namespace Converge\Core\Module;

/**
 * FabricManager — PHP 版 Fabric 总线
 *
 * 管理所有子系统的注册、启停、健康检查。
 * 每个 Fabric 是一组相关模块的容器，通过事件总线通信。
 *
 * 用法:
 *   $fm = FabricManager::init();
 *   $fm->boot();
 *   $fm->health(); // 全系统健康检查
 */
class FabricManager
{
    /** @var array<string, Fabric> */
    private array $fabrics = [];

    private static ?FabricManager $instance = null;

    public static function init(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('FabricManager not initialized. Call FabricManager::init() first.');
        }
        return self::$instance;
    }

    private function __construct(array $config)
    {
        $this->registerDefaults();
    }

    /**
     * Register all default fabrics — the 七可 architecture.
     */
    private function registerDefaults(): void
    {
        // ═══ 🔭 可观察 — L1 ═══
        $this->register('observability', new Fabric(
            name: 'Observability',
            layer: 'L1-🔭可观察',
            path: APP_ROOT . '/Observability',
            desc: 'HealthCheck + StructuredLogging + AlertNotifier(Telegram/Webhook/Email) + RequestId + Metrics',
            required: true,
        ));

        // ═══ 📋 可追溯 — L1 ═══
        $this->register('traceability', new Fabric(
            name: 'Traceability',
            layer: 'L1-📋可追溯',
            path: APP_ROOT . '/Traceability',
            desc: 'EventStore(SQLite CQRS) + AuditLog + CausationChain',
            required: true,
        ));

        // ═══ 🛡️ 无故障 — L1 ═══
        $this->register('resilience', new Fabric(
            name: 'Resilience',
            layer: 'L1-🛡️无故障',
            path: APP_ROOT . '/Resilience',
            desc: 'CircuitBreaker + RetryHandler(exp backoff) + FallbackManager',
            required: true,
        ));

        // ═══ 🛡️ 安全 — L1 ═══
        $this->register('security', new Fabric(
            name: 'Security',
            layer: 'L1-🛡️安全',
            path: APP_ROOT . '/Security',
            desc: 'BotDetector(5-layer) + VisitCap + IP Blacklist — 对标 Binom Protect',
            required: false,
        ));

        // ═══ ⚡ 高性能 — L1 ═══
        $this->register('performance', new Fabric(
            name: 'Performance',
            layer: 'L1-⚡高性能',
            path: APP_ROOT . '/Performance',
            desc: 'ClickBuffer(500/batch) + ConnectionPool + QueryOptimizer + RedisCache + ClickHouse — 100万/day',
            required: false,
        ));

        // ═══ 📣 增长 — L5 ═══
        $this->register('growth', new Fabric(
            name: 'Growth Engine',
            layer: 'L5-📣增长',
            path: APP_ROOT . '/Growth',
            desc: 'FeedbackLoop + AffiliateRecruiter + CommissionEngine + ReportDispatcher',
            required: false,
        ));

        // ═══ 🧬 可进化 — L5 ═══
        $this->register('evolution', new Fabric(
            name: 'Evolution',
            layer: 'L5-🧬可进化',
            path: APP_ROOT . '/Evolution',
            desc: 'OODA LearningLoop + SmartRotation + ABTestEngine + AttributionEngine + PerformanceAnalyzer + OptimizationEngine + ClickLossDetector',
            required: false,
        ));

        // ═══ 🧪 可验证 — L2 ═══
        $this->register('testing', new Fabric(
            name: 'Testing',
            layer: 'L2-🧪可验证',
            path: defined('ROOT_PATH') ? ROOT_PATH . '/tests' : APP_ROOT . '/tests',
            desc: 'Unit(phpunit) + Integration + Smoke tests',
            required: false,
        ));

        // ═══ 📐 可审核 — L2 ═══
        $this->register('validate', new Fabric(
            name: 'Architecture Validation',
            layer: 'L2-📐可审核',
            path: APP_ROOT . '/Core',
            desc: 'ModuleRegistry + validate_pipeline architecture check',
            required: false,
        ));

        // ═══ ⚡ 追踪核心 (不改) — L3 ═══
        $this->register('tracking', new Fabric(
            name: 'Tracking Core',
            layer: 'L3-⚡追踪',
            path: APP_ROOT . '/Tracking',
            desc: 'ClickPath + Redirector + PostbackDispatcher + LPRotator + ConversionTracker',
            required: true,
        ));

        // ═══ 📊 报表 (不改) — L3 ═══
        $this->register('stats', new Fabric(
            name: 'Statistics',
            layer: 'L3-📊报表',
            path: APP_ROOT . '/Stats',
            desc: 'CampaignStats + Breakdown + Aggregation + Caching',
            required: false,
        ));

        // ═══ 📦 实体 — L2 ═══
        $this->register('entities', new Fabric(
            name: 'Entity Layer',
            layer: 'L2-📦领域',
            path: APP_ROOT . '/Entity',
            desc: 'Campaign + Offer + TrafficSource + Network + LandingPage + User',
            required: true,
        ));

        // ═══ 🌐 API — L4 ═══
        $this->register('api', new Fabric(
            name: 'REST API v1',
            layer: 'L4-🌐接口',
            path: APP_ROOT . '/Api',
            desc: 'REST v1 + Controllers + Middleware + OpenAPI docs',
            required: false,
        ));

        // ═══ 🔐 认证 — L4 ═══
        $this->register('auth', new Fabric(
            name: 'Authentication',
            layer: 'L4-🔐认证',
            path: APP_ROOT . '/Auth',
            desc: 'Auth + CSRF + Permission(RBAC) + SingleAdmin',
            required: true,
        ));

        // ═══ 🏢 SaaS — L4 ═══
        $this->register('saas', new Fabric(
            name: 'SaaS Platform',
            layer: 'L4-🏢SaaS',
            path: APP_ROOT . '/SaaS',
            desc: 'TenantManager + SubscriptionManager(Free/Pro/Enterprise) + UsageMeter',
            required: false,
        ));

        // ═══ 🌍 GeoIP — L1 ═══
        $this->register('geoip', new Fabric(
            name: 'GeoIP Resolution',
            layer: 'L1-🌍基础',
            path: APP_ROOT . '/GeoIP',
            desc: '3-provider fallback: DBIP → IP2Location → IPinfo',
            required: true,
        ));

        // ═══ 📱 集成 — L3 ═══
        $this->register('integrations', new Fabric(
            name: 'Ad Platform Integrations',
            layer: 'L3-📱集成',
            path: APP_ROOT . '/Facebook',
            desc: 'Facebook CAPI + Marketing API + Google Ads API',
            required: false,
        ));
    }

    public function register(string $key, Fabric $fabric): void
    {
        $this->fabrics[$key] = $fabric;
    }

    public function boot(): array
    {
        $results = [];
        foreach ($this->fabrics as $key => $fabric) {
            try {
                $exists = is_dir($fabric->path) || is_file($fabric->path);
                $results[$key] = [
                    'name' => $fabric->name,
                    'layer' => $fabric->layer,
                    'status' => $exists ? 'active' : 'missing',
                ];

                if ($fabric->required && !$exists) {
                    throw new \RuntimeException("Required fabric '{$fabric->name}' not found at {$fabric->path}");
                }
            } catch (\Throwable $e) {
                $results[$key]['status'] = 'error';
                $results[$key]['error'] = $e->getMessage();
                if ($fabric->required) {
                    throw $e;
                }
            }
        }
        return $results;
    }

    /**
     * Full system health check — aggregates all fabric statuses.
     */
    public function health(): array
    {
        $ok = true;
        $fabrics = [];

        foreach ($this->fabrics as $key => $fabric) {
            $exists = is_dir($fabric->path) || is_file($fabric->path);
            $fabrics[$key] = [
                'name' => $fabric->name,
                'layer' => $fabric->layer,
                'required' => $fabric->required,
                'exists' => $exists,
            ];

            if ($fabric->required && !$exists) {
                $ok = false;
            }
        }

        return [
            'ok'    => $ok,
            'total' => count($this->fabrics),
            'required_ok' => count(array_filter($fabrics, fn($f) => $f['required'] && $f['exists']))
                          . '/' . count(array_filter($fabrics, fn($f) => $f['required'])),
            'fabrics' => $fabrics,
        ];
    }

    /** @return Fabric[] */
    public function all(): array { return $this->fabrics; }

    public function getFabric(string $key): ?Fabric { return $this->fabrics[$key] ?? null; }
}
