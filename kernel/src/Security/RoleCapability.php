<?php

declare(strict_types=1);

namespace Converge\Security;

/**
 * RoleCapability — 细粒度权限系统 (对标 WordPress Capabilities)
 *
 * 4 级角色 + 每个操作独立权限位:
 *   admin → 全部权限
 *   manager → 管理权限 (view+edit, 不含 delete+系统设置)
 *   editor → 编辑权限 (view+edit 自己的 campaigns)
 *   viewer → 只读 (view only)
 *
 * 用法:
 *   RoleCapability::can('campaign:delete', $userId);
 *   RoleCapability::getRolePermissions('manager'); // → ['campaign:view','campaign:create','campaign:edit',...]
 */
class RoleCapability
{
    // ── 权限位定义 ──
    public const PERM_CAMPAIGN_VIEW    = 'campaign:view';
    public const PERM_CAMPAIGN_CREATE  = 'campaign:create';
    public const PERM_CAMPAIGN_EDIT    = 'campaign:edit';
    public const PERM_CAMPAIGN_DELETE  = 'campaign:delete';
    public const PERM_CAMPAIGN_ARCHIVE = 'campaign:archive';

    public const PERM_OFFER_VIEW    = 'offer:view';
    public const PERM_OFFER_CREATE  = 'offer:create';
    public const PERM_OFFER_EDIT    = 'offer:edit';
    public const PERM_OFFER_DELETE  = 'offer:delete';

    public const PERM_LANDER_VIEW   = 'lander:view';
    public const PERM_LANDER_CREATE = 'lander:create';
    public const PERM_LANDER_EDIT   = 'lander:edit';
    public const PERM_LANDER_DELETE = 'lander:delete';

    public const PERM_TRAFFIC_VIEW   = 'traffic:view';
    public const PERM_TRAFFIC_CREATE = 'traffic:create';
    public const PERM_TRAFFIC_EDIT   = 'traffic:edit';
    public const PERM_TRAFFIC_DELETE = 'traffic:delete';

    public const PERM_STATS_VIEW  = 'stats:view';
    public const PERM_STATS_EXPORT = 'stats:export';

    public const PERM_USER_VIEW   = 'user:view';
    public const PERM_USER_CREATE = 'user:create';
    public const PERM_USER_EDIT   = 'user:edit';
    public const PERM_USER_DELETE = 'user:delete';

    public const PERM_SETTINGS_VIEW = 'settings:view';
    public const PERM_SETTINGS_EDIT = 'settings:edit';

    public const PERM_BILLING_VIEW = 'billing:view';
    public const PERM_BILLING_MANAGE = 'billing:manage';

    public const PERM_API_KEY_VIEW   = 'apikey:view';
    public const PERM_API_KEY_CREATE = 'apikey:create';
    public const PERM_API_KEY_REVOKE = 'apikey:revoke';

    public const PERM_AUDIT_VIEW = 'audit:view';

    // ── 角色定义 ──
    public const ROLES = [
        'admin' => [
            self::PERM_CAMPAIGN_VIEW, self::PERM_CAMPAIGN_CREATE, self::PERM_CAMPAIGN_EDIT,
            self::PERM_CAMPAIGN_DELETE, self::PERM_CAMPAIGN_ARCHIVE,
            self::PERM_OFFER_VIEW, self::PERM_OFFER_CREATE, self::PERM_OFFER_EDIT, self::PERM_OFFER_DELETE,
            self::PERM_LANDER_VIEW, self::PERM_LANDER_CREATE, self::PERM_LANDER_EDIT, self::PERM_LANDER_DELETE,
            self::PERM_TRAFFIC_VIEW, self::PERM_TRAFFIC_CREATE, self::PERM_TRAFFIC_EDIT, self::PERM_TRAFFIC_DELETE,
            self::PERM_STATS_VIEW, self::PERM_STATS_EXPORT,
            self::PERM_USER_VIEW, self::PERM_USER_CREATE, self::PERM_USER_EDIT, self::PERM_USER_DELETE,
            self::PERM_SETTINGS_VIEW, self::PERM_SETTINGS_EDIT,
            self::PERM_BILLING_VIEW, self::PERM_BILLING_MANAGE,
            self::PERM_API_KEY_VIEW, self::PERM_API_KEY_CREATE, self::PERM_API_KEY_REVOKE,
            self::PERM_AUDIT_VIEW,
        ],
        'manager' => [
            self::PERM_CAMPAIGN_VIEW, self::PERM_CAMPAIGN_CREATE, self::PERM_CAMPAIGN_EDIT,
            self::PERM_CAMPAIGN_ARCHIVE,
            self::PERM_OFFER_VIEW, self::PERM_OFFER_CREATE, self::PERM_OFFER_EDIT,
            self::PERM_LANDER_VIEW, self::PERM_LANDER_CREATE, self::PERM_LANDER_EDIT,
            self::PERM_TRAFFIC_VIEW, self::PERM_TRAFFIC_CREATE, self::PERM_TRAFFIC_EDIT,
            self::PERM_STATS_VIEW, self::PERM_STATS_EXPORT,
            self::PERM_USER_VIEW,
            self::PERM_SETTINGS_VIEW,
            self::PERM_BILLING_VIEW,
            self::PERM_API_KEY_VIEW,
        ],
        'editor' => [
            self::PERM_CAMPAIGN_VIEW, self::PERM_CAMPAIGN_CREATE, self::PERM_CAMPAIGN_EDIT,
            self::PERM_OFFER_VIEW, self::PERM_OFFER_CREATE, self::PERM_OFFER_EDIT,
            self::PERM_LANDER_VIEW, self::PERM_LANDER_CREATE, self::PERM_LANDER_EDIT,
            self::PERM_TRAFFIC_VIEW,
            self::PERM_STATS_VIEW,
        ],
        'viewer' => [
            self::PERM_CAMPAIGN_VIEW,
            self::PERM_OFFER_VIEW,
            self::PERM_LANDER_VIEW,
            self::PERM_TRAFFIC_VIEW,
            self::PERM_STATS_VIEW,
        ],
    ];

    // ── 角色标签 ──
    public const ROLE_LABELS = [
        'admin' => '管理员',
        'manager' => '经理',
        'editor' => '编辑',
        'viewer' => '观察者',
    ];

    /**
     * 检查用户是否有某权限
     */
    public static function can(string $permission, ?array $user): bool
    {
        if (!$user || empty($user['role'])) return false;
        $role = $user['role'];
        if ($role === 'admin') return true; // admin = all
        $perms = self::ROLES[$role] ?? [];
        return in_array($permission, $perms, true);
    }

    /**
     * 获取角色的权限列表
     */
    public static function getRolePermissions(string $role): array
    {
        return self::ROLES[$role] ?? [];
    }

    /**
     * 权限分组 (用于 UI 展示)
     */
    public static function getPermissionGroups(): array
    {
        return [
            'Campaign' => [self::PERM_CAMPAIGN_VIEW, self::PERM_CAMPAIGN_CREATE, self::PERM_CAMPAIGN_EDIT, self::PERM_CAMPAIGN_DELETE, self::PERM_CAMPAIGN_ARCHIVE],
            'Offer' => [self::PERM_OFFER_VIEW, self::PERM_OFFER_CREATE, self::PERM_OFFER_EDIT, self::PERM_OFFER_DELETE],
            'Landing Page' => [self::PERM_LANDER_VIEW, self::PERM_LANDER_CREATE, self::PERM_LANDER_EDIT, self::PERM_LANDER_DELETE],
            'Traffic Source' => [self::PERM_TRAFFIC_VIEW, self::PERM_TRAFFIC_CREATE, self::PERM_TRAFFIC_EDIT, self::PERM_TRAFFIC_DELETE],
            'Statistics' => [self::PERM_STATS_VIEW, self::PERM_STATS_EXPORT],
            'User Management' => [self::PERM_USER_VIEW, self::PERM_USER_CREATE, self::PERM_USER_EDIT, self::PERM_USER_DELETE],
            'Settings' => [self::PERM_SETTINGS_VIEW, self::PERM_SETTINGS_EDIT],
            'Billing' => [self::PERM_BILLING_VIEW, self::PERM_BILLING_MANAGE],
            'API Keys' => [self::PERM_API_KEY_VIEW, self::PERM_API_KEY_CREATE, self::PERM_API_KEY_REVOKE],
            'Audit' => [self::PERM_AUDIT_VIEW],
        ];
    }
}
