<?php

declare(strict_types=1);

namespace Converge\UI;

use Converge\Security\Permission;

/**
 * ViewContext — 统一视图上下文 (L4→L3→L2→L1 单向数据流)
 *
 * 解决组件化 UI 的 Props Drilling 和权限状态不一致问题:
 *   - 所有视图 (PHP + Latte) 通过一个对象获取 user/can/roles/locale
 *   - 禁止在 L3/L4 模板中直接读 $GLOBALS 或 $_SESSION
 *   - Latte 模板: $context->can('campaign.create')
 *   - PHP 视图:  $context->can('campaign.create')
 *
 * 用法:
 *   // 从当前请求自动构建
 *   $ctx = ViewContext::fromGlobals();
 *
 *   // 手动构建 (测试/CLI)
 *   $ctx = new ViewContext(user: [...], perm: $perm, locale: 'zh');
 *
 *   // 在 Latte 中自动可用 (LatteEngine 注入)
 *   {$context->user['username']}
 *   <button n:if="$context->can('campaign.create')">新建</button>
 *
 *   // 在 PHP 视图中
 *   <?php if ($ctx->can('offer.manage')): ?>
 *       <button>新建 Offer</button>
 *   <?php endif; ?>
 */
class ViewContext
{
    /**
     * @param array{id?: int, username?: string, email?: string} $user
     * @param Permission|null $perm
     * @param array<string> $roles
     * @param string $locale  'en' | 'zh'
     */
    public function __construct(
        public readonly array $user,
        private readonly ?Permission $perm = null,
        public readonly array $roles = [],
        public readonly string $locale = 'en',
    ) {}

    // ═══════════════════════════════════════
    // Factory
    // ═══════════════════════════════════════

    /**
     * 从当前请求的全局状态构建 ViewContext。
     *
     * 读取 $_SESSION 和 $GLOBALS (auth + permission + locale)。
     * 这是主入口 — 99% 的请求通过此方法创建上下文。
     */
    public static function fromGlobals(): self
    {
        $auth  = $GLOBALS['auth'] ?? null;
        $perm  = $GLOBALS['permission'] ?? null;

        // 用户信息
        $user = [];
        if ($auth && method_exists($auth, 'getCurrentUser')) {
            $u = $auth->getCurrentUser();
            $user = [
                'id'       => (int) ($u['id'] ?? 0),
                'username' => (string) ($u['username'] ?? ''),
                'email'    => (string) ($u['email'] ?? ''),
            ];
        } elseif (!empty($_SESSION['user_id'])) {
            $user = [
                'id'       => (int) $_SESSION['user_id'],
                'username' => (string) ($_SESSION['username'] ?? ''),
                'email'    => '',
            ];
        }

        // 角色列表
        $roles = (array) ($_SESSION['role_names'] ?? $_SESSION['roles'] ?? []);
        if (empty($roles) && !empty($_SESSION['role_ids'])) {
            $roles = ['user']; // fallback — 有登录但无角色名
        }

        // 语言
        $locale = (string) ($_SESSION['lang'] ?? $_COOKIE['lang'] ?? 'en');

        return new self(
            user: $user,
            perm: $perm instanceof Permission ? $perm : null,
            roles: $roles,
            locale: $locale,
        );
    }

    // ═══════════════════════════════════════
    // Permission Checks
    // ═══════════════════════════════════════

    /** 检查单个权限 (Latte: $context->can('campaign.create')) */
    public function can(string $permission): bool
    {
        // 无登录 → 全部拒绝
        if (!$this->perm) {
            return false;
        }
        return $this->perm->hasPermission($permission);
    }

    /** 检查是否拥有任一权限 */
    public function canAny(array $permissions): bool
    {
        if (!$this->perm) return false;
        return $this->perm->hasAnyPermission($permissions);
    }

    /** 检查是否拥有全部权限 */
    public function canAll(array $permissions): bool
    {
        if (!$this->perm) return false;
        return $this->perm->hasAllPermissions($permissions);
    }

    // ═══════════════════════════════════════
    // Role Checks
    // ═══════════════════════════════════════

    public function isAdmin(): bool
    {
        if (!$this->perm) {
            return in_array('admin', $this->roles, true);
        }
        return $this->perm->hasRole('admin');
    }

    public function isManager(): bool
    {
        return in_array('manager', $this->roles, true);
    }

    public function isViewer(): bool
    {
        return in_array('viewer', $this->roles, true);
    }

    /** 是否已登录 */
    public function isAuthenticated(): bool
    {
        return !empty($this->user['id']);
    }

    // ═══════════════════════════════════════
    // Convenience: 常用快捷权限
    // ═══════════════════════════════════════

    /** 常用权限批量检查 — 返回扁平的 bool 数组供模板使用 */
    public function permissions(): array
    {
        $common = [
            'campaign.view',
            'campaign.create',
            'campaign.edit',
            'campaign.delete',
            'offer.manage',
            'landing_page.manage',
            'traffic_source.manage',
            'network.manage',
            'postback.manage',
            'settings.edit',
            'user.manage',
            'billing.view',
        ];

        $map = [];
        foreach ($common as $p) {
            $key = str_replace('.', '_', $p);
            $map[$key] = $this->can($p);
        }
        return $map;
    }

    // ═══════════════════════════════════════
    // Serialization
    // ═══════════════════════════════════════

    /** 转为数组 (供 JSON 响应或 JS 注入) */
    public function toArray(): array
    {
        return [
            'user' => $this->user,
            'roles' => $this->roles,
            'locale' => $this->locale,
            'isAdmin' => $this->isAdmin(),
            'isAuthenticated' => $this->isAuthenticated(),
        ];
    }
}
