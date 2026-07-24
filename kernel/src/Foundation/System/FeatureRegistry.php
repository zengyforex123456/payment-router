<?php

declare(strict_types=1);

namespace Converge\Foundation\System;

/**
 * FeatureRegistry — 可进化: 功能可插拔，不写死 if/else
 *
 * 用户层面: 套餐功能可配置，不用改代码
 * 系统层面: 运行时开关，出问题秒关
 * 开发者层面: 新功能注册即用，不碰核心代码
 *
 * 用法:
 *   FeatureRegistry::register('smart_rotation', ['plans' => ['pro','enterprise']]);
 *   if (FeatureRegistry::isEnabled('smart_rotation', $tenantId)) { ... }
 *   FeatureRegistry::disable('smart_rotation'); // 紧急关闭
 */
class FeatureRegistry
{
    /** @var array<string, array> */
    private static array $features = [];

    /** @var array<string, bool> 运行时覆盖 (紧急开关) */
    private static array $overrides = [];

    /** @var array<string, callable> 动态判断器 */
    private static array $guards = [];

    /** @var array<int, string> 进程内套餐缓存 — 避免每次 isEnabled 都新建 mysqli 查 saas_tenants */
    private static array $planCache = [];

    // ═══ Registration ═══

    /**
     * Register a feature.
     *
     * @param string $name    Feature key
     * @param array  $config  ['plans' => ['pro','enterprise'], 'default' => true, 'description' => '...']
     */
    public static function register(string $name, array $config = []): void
    {
        self::$features[$name] = array_merge([
            'plans' => ['pro', 'enterprise'],
            'default' => false,
            'description' => '',
            'category' => 'general',  // tracking | optimization | reporting | security
        ], $config);
    }

    /**
     * Register a dynamic guard (callback that decides if feature is available).
     */
    public static function guard(string $name, callable $fn): void
    {
        self::$guards[$name] = $fn;
    }

    // ═══ Runtime Checks ═══

    /**
     * Check if a feature is enabled for a given tenant/context.
     */
    public static function isEnabled(string $name, int $tenantId = 0): bool
    {
        self::ensureBootstrapped();

        // 1. Runtime override takes priority
        if (isset(self::$overrides[$name])) {
            return self::$overrides[$name];
        }

        // 2. Dynamic guard
        if (isset(self::$guards[$name])) {
            return (bool)(self::$guards[$name])($tenantId);
        }

        // 3. Plan-based check
        $config = self::$features[$name] ?? null;
        if (!$config) return false;

        $plan = self::resolvePlan($tenantId);
        return in_array($plan, $config['plans'] ?? [], true);
    }

    // ═══ Emergency Controls ═══

    /** Emergency disable (runtime, no code change) */
    public static function disable(string $name): void
    {
        self::$overrides[$name] = false;
        error_log("[FeatureRegistry] 🚨 EMERGENCY DISABLE: {$name}");
    }

    /** Re-enable after emergency */
    public static function enable(string $name): void
    {
        unset(self::$overrides[$name]);
        error_log("[FeatureRegistry] ✅ Re-enabled: {$name}");
    }

    /** Get all registered features with status */
    public static function all(int $tenantId = 0): array
    {
        self::ensureBootstrapped();
        $result = [];
        foreach (self::$features as $name => $config) {
            $result[$name] = [
                'enabled' => self::isEnabled($name, $tenantId),
                'category' => $config['category'],
                'description' => $config['description'],
                'plans' => $config['plans'],
                'overridden' => isset(self::$overrides[$name]),
            ];
        }
        return $result;
    }

    // ═══ Internal ═══

    private static function resolvePlan(int $tenantId): string
    {
        if (isset(self::$planCache[$tenantId])) {
            return self::$planCache[$tenantId];
        }
        return self::$planCache[$tenantId] = self::computePlan($tenantId);
    }

    private static function computePlan(int $tenantId): string
    {
        if ($tenantId <= 0) {
            // 开发环境全功能
            if (defined('APP_DEBUG') && APP_DEBUG) return 'pro';
            // Self-hosted: check license
            if (defined('LICENSE_KEY') && LICENSE_KEY) {
                $lm = new LicenseManager();
                $r = $lm->validate(LICENSE_KEY, $_SERVER['HTTP_HOST'] ?? '');
                return $r->plan; // 'pro' | 'enterprise' | 'free'
            }
            return 'free';
        }
        // SaaS: query tenant plan (参数化查询, 防注入 + 过安全门禁)
        try {
            $db = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            $stmt = $db->prepare("SELECT p.slug FROM saas_tenants t JOIN saas_plans p ON p.id = t.plan_id WHERE t.id = ?");
            $stmt->bind_param('i', $tenantId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return $row['slug'] ?? 'free';
        } catch (\Throwable) {
            return 'free';
        }
    }

    /** 清进程内套餐缓存 (测试 / 租户套餐变更后调用) */
    public static function resetPlanCache(): void
    {
        self::$planCache = [];
    }

    // ═══ Bootstrap ═══

    /**
     * 惰性自初始化 — 任何入口首次 isEnabled/all 时确保 features 已注册。
     * 补救根因: bootstrap() 从没被生产入口显式调用 → $features 空 → 门控恒 false(付费也报 requires Pro)。
     * register() 覆盖写, 幂等安全。
     */
    private static function ensureBootstrapped(): void
    {
        if (self::$features === []) {
            self::bootstrap();
        }
    }

    /** Register all default features */
    public static function bootstrap(): void
    {
        self::register('smart_rotation', ['plans' => ['pro', 'enterprise'], 'category' => 'optimization', 'description' => 'Bayesian EPC auto-optimization']);
        self::register('ab_test', ['plans' => ['pro', 'enterprise'], 'category' => 'optimization', 'description' => 'Monte Carlo A/B testing']);
        self::register('advanced_attribution', ['plans' => ['pro', 'enterprise'], 'category' => 'reporting', 'description' => '5-model multi-touch attribution']);
        self::register('funnel_analytics', ['plans' => ['pro', 'enterprise'], 'category' => 'reporting', 'description' => 'ToFu→MoFu→BoFu funnel']);
        self::register('ooda_learning', ['plans' => ['pro', 'enterprise'], 'category' => 'optimization', 'description' => 'OODA self-learning loop']);
        self::register('api_access', ['plans' => ['pro', 'enterprise'], 'category' => 'general', 'description' => 'REST API access']);
        self::register('clickhouse', ['plans' => ['enterprise'], 'category' => 'reporting', 'description' => 'ClickHouse analytics']);
        self::register('white_label', ['plans' => ['enterprise'], 'category' => 'general', 'description' => 'Remove Converge branding']);
        self::register('bot_detection', ['plans' => ['free', 'pro', 'enterprise'], 'category' => 'security', 'default' => true, 'description' => '5-layer bot detection']);
        self::register('health_monitoring', ['plans' => ['free', 'pro', 'enterprise'], 'category' => 'security', 'default' => true, 'description' => '/health endpoint + alerts']);
        self::register('event_store', ['plans' => ['free', 'pro', 'enterprise'], 'category' => 'general', 'default' => true, 'description' => 'CQRS event sourcing']);
    }
}
