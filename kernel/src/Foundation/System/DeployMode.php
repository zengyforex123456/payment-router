<?php

declare(strict_types=1);

namespace Converge\Foundation\System;

/**
 * DeployMode — 三模式中枢
 *
 * 所有模块通过此类判断当前部署模式，无需直接读 define。
 *
 * 用法:
 *   $mode = DeployMode::detect();
 *   if ($mode->isSelfHosted()) { ... }
 *   if ($mode->isSaaS()) { ... }
 *   if ($mode->can('advanced_attribution')) { ... }
 *
 * 功能矩阵:
 *   self_hosted:  全功能 — 自部署，用户自己运维
 *   saas_free:     基础功能 — 获客引擎，500conv/mo
 *   saas_pro:      高级功能 — $79/mo
 *   saas_enterprise: 全功能 + 白标 + SLA — $399+/mo
 */
class DeployMode
{
    public const SELF_HOSTED = 'self_hosted';
    public const SAAS = 'saas';
    public const ENTERPRISE = 'enterprise';

    private string $mode;
    private string $planSlug;
    private int $tenantId;
    private ?array $tenantData;

    /** Feature flags for current mode */
    private array $features;

    /** Feature definitions per plan/mode */
    private const FEATURE_MATRIX = [
        'self_hosted_free' => [
            'advanced_attribution' => false,
            'smart_rotation' => false,
            'ab_test' => false,
            'ooda_learning' => false,
            'api_access' => false,
            'multi_user' => false,
            'white_label' => false,
            'clickhouse' => false,
            'redis_cache' => false,
            'funnel_analytics' => false,
            'partner_scoring' => false,
            'alerting' => false,
            'sla' => false,
            'support' => 'community',
            'conversions_limit' => 0,
            'data_sources' => 0,
            'team_members' => 0,
        ],
        self::SELF_HOSTED => [
            'advanced_attribution' => true,
            'smart_rotation' => true,
            'ab_test' => true,
            'ooda_learning' => true,
            'api_access' => true,
            'multi_user' => true,
            'white_label' => false,
            'clickhouse' => true,
            'redis_cache' => true,
            'funnel_analytics' => true,
            'partner_scoring' => true,
            'alerting' => true,
            'sla' => false,
            'support' => 'community',
            'conversions_limit' => 0,    // 0 = unlimited
            'data_sources' => 0,
            'team_members' => 0,
        ],
        'saas_free' => [
            'advanced_attribution' => false,
            'smart_rotation' => false,
            'ab_test' => false,
            'ooda_learning' => false,
            'api_access' => false,
            'multi_user' => false,
            'white_label' => false,
            'clickhouse' => false,
            'redis_cache' => false,
            'funnel_analytics' => false,
            'partner_scoring' => false,
            'alerting' => false,
            'sla' => false,
            'support' => 'community',
            'conversions_limit' => 500,
            'data_sources' => 3,
            'team_members' => 1,
        ],
        'saas_pro' => [
            'advanced_attribution' => true,
            'smart_rotation' => true,
            'ab_test' => true,
            'ooda_learning' => true,
            'api_access' => true,
            'multi_user' => true,
            'white_label' => true,
            'clickhouse' => true,
            'redis_cache' => true,
            'funnel_analytics' => true,
            'partner_scoring' => true,
            'alerting' => true,
            'sla' => true,
            'support' => 'email',
            'conversions_limit' => 5000,
            'data_sources' => 10,
            'team_members' => 5,
        ],
        'saas_enterprise' => [
            'advanced_attribution' => true,
            'smart_rotation' => true,
            'ab_test' => true,
            'ooda_learning' => true,
            'api_access' => true,
            'multi_user' => true,
            'white_label' => true,
            'clickhouse' => true,
            'redis_cache' => true,
            'funnel_analytics' => true,
            'partner_scoring' => true,
            'alerting' => true,
            'sla' => true,
            'support' => 'dedicated',
            'conversions_limit' => 0,    // unlimited
            'data_sources' => 0,         // unlimited
            'team_members' => 0,         // unlimited
        ],
        self::ENTERPRISE => [
            'advanced_attribution' => true,
            'smart_rotation' => true,
            'ab_test' => true,
            'ooda_learning' => true,
            'api_access' => true,
            'multi_user' => true,
            'white_label' => true,
            'clickhouse' => true,
            'redis_cache' => true,
            'funnel_analytics' => true,
            'partner_scoring' => true,
            'alerting' => true,
            'sla' => true,
            'support' => 'dedicated',
            'conversions_limit' => 0,
            'data_sources' => 0,
            'team_members' => 0,
        ],
    ];

    private static ?DeployMode $instance = null;

    public function __construct(
        string $mode,
        string $planSlug = 'free',
        int $tenantId = 0,
        ?array $tenantData = null,
    ) {
        $this->mode = $mode;
        $this->planSlug = $planSlug;
        $this->tenantId = $tenantId;
        $this->tenantData = $tenantData;
        $this->features = $this->resolveFeatures();
    }

    public static function init(string $mode, string $planSlug = 'free', int $tenantId = 0): self
    {
        self::$instance = new self($mode, $planSlug, $tenantId);
        return self::$instance;
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            // Auto-detect from config
            $mode = defined('DEPLOY_MODE') ? DEPLOY_MODE : self::SELF_HOSTED;
            self::$instance = new self($mode);
        }
        return self::$instance;
    }

    /**
     * Auto-detect from config constants.
     */
    public static function detect(): self
    {
        $mode = defined('DEPLOY_MODE') ? DEPLOY_MODE : self::SELF_HOSTED;

        if ($mode === self::SAAS && class_exists('\Converge\Modules\SaasReferral\TenantManager')) {
            try {
                $plan = \Converge\Modules\SaasReferral\TenantManager::get()->currentPlan();
                return new self($mode, $plan);
            } catch (\Throwable $e) {
                // TenantManager 尚未初始化 → 回落到无 plan 的 SaaS 上下文
            }
        }

        return new self($mode);
    }

    // ═══════════════════════════════════════
    // Mode checks
    // ═══════════════════════════════════════

    public function isSelfHosted(): bool
    {
        return $this->mode === self::SELF_HOSTED;
    }

    public function isSaaS(): bool
    {
        return $this->mode === self::SAAS;
    }

    public function isEnterprise(): bool
    {
        return $this->mode === self::ENTERPRISE;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getPlanSlug(): string
    {
        return $this->planSlug;
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    // ═══════════════════════════════════════
    // Feature gates — the core API
    // ═══════════════════════════════════════

    /**
     * Check if the current deployment can use a feature.
     */
    public function can(string $feature): bool
    {
        // Delegate to FeatureRegistry; 传当前租户 id 让 SaaS 套餐分支生效(否则 tenantId=0 走 LICENSE/free)
        $tid = $this->tenantId > 0 ? $this->tenantId : \Converge\Modules\SaasReferral\TenantContext::current();
        return FeatureRegistry::isEnabled($feature, $tid);
    }

    /**
     * Get the limit for a numeric feature (0 = unlimited).
     */
    public function limit(string $feature): int
    {
        return (int)($this->features[$feature] ?? 0);
    }

    /**
     * Get all features for current mode.
     */
    public function features(): array
    {
        return $this->features;
    }

    /**
     * Get support level.
     */
    public function supportLevel(): string
    {
        return $this->features['support'] ?? 'community';
    }

    // ═══════════════════════════════════════
    // Plan transitions
    // ═══════════════════════════════════════

    /**
     * Check if upgrading from current plan to a target is possible.
     */
    public function canUpgradeTo(string $targetPlan): bool
    {
        $order = ['saas_free' => 0, 'saas_pro' => 1, 'saas_enterprise' => 2];
        $current = $order[$this->getFeatureKey()] ?? 0;
        $target = $order[$targetPlan] ?? 0;
        return $target > $current;
    }

    /**
     * Get upgrade CTA for current plan.
     */
    public function upgradeCta(): array
    {
        if ($this->isSelfHosted()) {
            return [
                'current' => 'self_hosted',
                'next' => 'saas_pro',
                'message' => 'Want managed hosting? Try Converge Cloud.',
                'url' => 'https://converge.io/cloud',
            ];
        }

        return match ($this->getFeatureKey()) {
            'saas_free' => [
                'current' => 'free',
                'next' => 'pro',
                'message' => 'Unlock advanced attribution, A/B testing, and 5,000 conversions/month.',
                'url' => '/billing/upgrade?plan=pro',
                'price' => '$79/mo',
            ],
            'saas_pro' => [
                'current' => 'pro',
                'next' => 'enterprise',
                'message' => 'Need unlimited conversions, dedicated support, and white-label?',
                'url' => '/billing/upgrade?plan=enterprise',
                'price' => '$399/mo',
            ],
            default => [
                'current' => 'enterprise',
                'next' => null,
                'message' => 'You are on the highest plan.',
                'url' => null,
            ],
        };
    }

    // ═══════════════════════════════════════
    // UI helpers
    // ═══════════════════════════════════════

    /**
     * Get the badge text for the current plan (shown in dashboard header).
     */
    public function planBadge(): string
    {
        if ($this->isSelfHosted()) return 'Self-Hosted';
        return ucfirst($this->planSlug);
    }

    /**
     * Get the plan badge color class.
     */
    public function planBadgeClass(): string
    {
        return match ($this->getFeatureKey()) {
            'saas_free' => 'badge-info',
            'saas_pro' => 'badge-success',
            'saas_enterprise', self::ENTERPRISE => 'badge-warning',
            default => 'badge-info',
        };
    }

    /**
     * Should we show the "Upgrade" button in the UI?
     */
    public function showUpgradePrompt(): bool
    {
        return $this->getFeatureKey() === 'saas_free' || $this->getFeatureKey() === 'saas_pro';
    }

    /**
     * Render the upgrade banner HTML (inject into dashboard).
     */
    public function renderUpgradeBanner(): string
    {
        if (!$this->showUpgradePrompt()) return '';

        $cta = $this->upgradeCta();
        $pct = $this->usagePercent();

        $html = '<div class="alert alert-info" style="display:flex;justify-content:space-between;align-items:center">';
        $html .= "<span>{$cta['message']}</span>";
        $html .= "<a href='{$cta['url']}' class='btn btn-primary btn-sm'>{$cta['price']} — Upgrade</a>";
        $html .= '</div>';

        return $html;
    }

    /**
     * Get current usage percentage (only for SaaS mode).
     */
    public function usagePercent(): float
    {
        if (!$this->isSaaS()) return 0;
        try {
            $sm = new \Converge\Modules\SaasReferral\SubscriptionManager(
                new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME)
            );
            return $sm->usagePercent($this->tenantId, 'conversions');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ═══════════════════════════════════════
    // Internal
    // ═══════════════════════════════════════

    private function getFeatureKey(): string
    {
        if ($this->mode === self::SELF_HOSTED) return self::SELF_HOSTED;
        if ($this->mode === self::ENTERPRISE) return self::ENTERPRISE;
        return 'saas_' . $this->planSlug;
    }

    private function resolveFeatures(): array
    {
        $key = $this->getFeatureKey();

        // 开发环境全功能开放
        if ($key === self::SELF_HOSTED && defined('APP_DEBUG') && APP_DEBUG) {
            return self::FEATURE_MATRIX[self::SELF_HOSTED] ?? [];
        }

        // Self-hosted: check license key to determine feature level
        if ($key === self::SELF_HOSTED && defined('LICENSE_KEY') && LICENSE_KEY) {
            $lm = new LicenseManager();
            $result = $lm->validate(LICENSE_KEY, $_SERVER['HTTP_HOST'] ?? '');
            if ($result->valid) {
                return $result->isEnterprise()
                    ? (self::FEATURE_MATRIX[self::ENTERPRISE] ?? [])
                    : (self::FEATURE_MATRIX['saas_pro'] ?? []);
            }
            // Invalid license → fall through to free features
            return self::FEATURE_MATRIX['self_hosted_free'] ?? [];
        }

        // Self-hosted without license → free features
        if ($key === self::SELF_HOSTED) {
            return self::FEATURE_MATRIX['self_hosted_free'] ?? self::FEATURE_MATRIX[$key] ?? [];
        }

        return self::FEATURE_MATRIX[$key] ?? self::FEATURE_MATRIX['self_hosted_free'] ?? [];
    }

    // ═══════════════════════════════════════
    // Convenience — static shortcuts
    // ═══════════════════════════════════════

    /** 当前租户 id (会话/API-key 覆盖) — 传进门控让 SaaS 套餐分支生效, 否则 tenantId=0 走 LICENSE/free */
    private static function currentTenantId(): int
    {
        return \Converge\Modules\SaasReferral\TenantContext::current();
    }

    public static function advancedAttribution(): bool { return FeatureRegistry::isEnabled('advanced_attribution', self::currentTenantId()); }
    public static function smartRotation(): bool { return FeatureRegistry::isEnabled('smart_rotation', self::currentTenantId()); }
    public static function abTest(): bool { return FeatureRegistry::isEnabled('ab_test', self::currentTenantId()); }
    public static function oodaLearning(): bool { return FeatureRegistry::isEnabled('ooda_learning', self::currentTenantId()); }
    public static function apiAccess(): bool { return FeatureRegistry::isEnabled('api_access', self::currentTenantId()); }
    public static function funnelAnalytics(): bool { return FeatureRegistry::isEnabled('funnel_analytics', self::currentTenantId()); }
}
