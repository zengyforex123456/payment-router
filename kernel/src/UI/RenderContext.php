<?php
declare(strict_types=1);

namespace Converge\UI;

use Converge\Security\RoleCapability;

/**
 * RenderContext — 区块渲染管线运行时上下文
 *
 * 贯穿 PageRenderer → Block::render → DataSource::fetch 全链路。
 * 携带用户身份、租户 ID、角色，让区块和数据源能做权限判断和租户隔离。
 *
 * 与 ViewContext 的关系:
 *   - ViewContext = DB-backed Permission, 注入到 Latte 模板 ($context->can('...'))
 *   - RenderContext = 轻量静态 RoleCapability, 注入到 PHP Block 渲染管线
 *   - 两者互补，不冲突。RenderContext 可桥接 ViewContext 获得更丰富的权限检查
 *
 * 设计: 不可变值对象 + 静态请求级访问器
 *   - 值对象: 每个请求创建一个 RenderContext 实例
 *   - 静态访问器: Block 内部通过 RenderContext::current() 读取，无需改 19 个 Block 方法签名
 *
 * 用法:
 *   // 入口点 (page.php / landing.php)
 *   $ctx = RenderContext::fromGlobals();
 *   $html = $renderer->render($slug, $ctx);
 *
 *   // 在任意 Block::render() 内部:
 *   $ctx = RenderContext::current();
 *   if ($ctx?->can('stats:view')) { ... }          // 权限判断
 *   $tenantId = $ctx?->tenantId;                    // 租户隔离
 *
 *   // 在 DataSource::fetch() 内部:
 *   $ctx = RenderContext::current();
 *   $sql .= ' WHERE tenant_id = ' . $ctx?->tenantId;  // 自动过滤
 */
class RenderContext
{
    // ─── 静态访问器（请求级，非持久） ───

    private static ?RenderContext $current = null;

    public static function current(): ?self
    {
        return self::$current;
    }

    /** 设为当前请求上下文（PageRenderer 自动调用） */
    public function enter(): void
    {
        self::$current = $this;
    }

    /** 退出当前上下文 */
    public function exit(): void
    {
        self::$current = null;
    }

    // ─── 只读属性 ───

    /** @var array<string, mixed>|null 用户记录 */
    public readonly ?array $user;

    public readonly ?int $userId;

    /** 租户 ID — 数据隔离的核心字段 */
    public readonly ?int $tenantId;

    /** 角色名: 'admin' | 'manager' | 'editor' | 'viewer' */
    public readonly ?string $role;

    public readonly bool $isAuthenticated;

    /** 路由参数（$_GET 或页面跳转传入）: id, page, filter... */
    public readonly array $routeParams;

    /** 可选桥接 ViewContext（有则用其 DB-backed Permission） */
    public readonly ?ViewContext $viewContext;

    // ─── 构造 ───

    /**
     * @param array<string, mixed>|null $user 用户记录，含 id/role/tenant_id
     */
    public function __construct(?array $user = null, ?ViewContext $viewContext = null, array $routeParams = [])
    {
        $this->user = $user;
        $this->userId   = isset($user['id']) ? (int)$user['id'] : null;
        $this->tenantId = isset($user['tenant_id']) ? (int)$user['tenant_id'] : null;
        $this->role     = $user['role'] ?? null;
        $this->isAuthenticated = $user !== null && !empty($user['id']);
        $this->viewContext = $viewContext;
        $this->routeParams = $routeParams;
    }

    // ─── 工厂方法 ───

    /**
     * 从全局状态自动构建（Session + Auth + ViewContext）
     * 这是入口点最常用的创建方式。
     */
    public static function fromGlobals(): self
    {
        // 尝试从 Auth 对象获取完整用户（含 tenant_id, role）
        $auth = $GLOBALS['auth'] ?? null;
        $user = null;

        if ($auth && method_exists($auth, 'getCurrentUser')) {
            $u = $auth->getCurrentUser();
            if (is_array($u)) {
                $user = [
                    'id'        => (int)($u['id'] ?? 0),
                    'username'  => (string)($u['username'] ?? ''),
                    'email'     => (string)($u['email'] ?? ''),
                    'role'      => (string)($u['role'] ?? ''),
                    'tenant_id' => isset($u['tenant_id']) ? (int)$u['tenant_id'] : null,
                ];
            }
        }

        // Fallback: Session
        if (!$user && !empty($_SESSION['user_id'])) {
            $user = [
                'id'        => (int)$_SESSION['user_id'],
                'username'  => (string)($_SESSION['username'] ?? ''),
                'role'      => (string)($_SESSION['role'] ?? ''),
                'tenant_id' => isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null,
            ];
        }

        // 桥接 ViewContext（有则复用其 Permission）
        $vc = $GLOBALS['viewContext'] ?? null;
        if (!$vc instanceof ViewContext) {
            $vc = null;
        }

        // 路由参数（排除内部参数）
        $routeParams = $_GET;
        unset($routeParams['slug'], $routeParams['dark'], $routeParams['lang']);

        return new self($user ?: null, $vc, $routeParams);
    }

    /** 公开访问（未登录用户、Landing Page） */
    public static function anonymous(): self
    {
        return new self(null);
    }

    /** 从用户数组创建（CLI、测试） */
    public static function fromUser(array $user, ?ViewContext $vc = null, array $routeParams = []): self
    {
        return new self($user, $vc, $routeParams);
    }

    /** 从已有 ViewContext 桥接 */
    public static function fromViewContext(ViewContext $vc): self
    {
        $user = $vc->user;
        // ViewContext 的 user 不含 tenant_id/role → 从 roles 推断
        if (empty($user['role']) && !empty($vc->roles)) {
            $user['role'] = $vc->roles[0];
        }
        return new self($user, $vc);
    }

    // ─── 权限助手 ───

    /**
     * 检查当前用户是否拥有某权限。
     * 统一使用 RoleCapability（冒号格式: 'campaign:view', 'stats:export'）。
     *
     * ViewContext 仍可用于 Latte 模板中 {$context->can('campaign.create')}，
     * 但 RenderContext 统一走 RoleCapability 避免双权限格式混淆。
     */
    public function can(string $permission): bool
    {
        return RoleCapability::can($permission, $this->user);
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    public function isAdmin(): bool
    {
        if ($this->viewContext) {
            return $this->viewContext->isAdmin();
        }
        return $this->role === 'admin';
    }

    public function isAuthenticated(): bool
    {
        if ($this->viewContext) {
            return $this->viewContext->isAuthenticated();
        }
        return $this->isAuthenticated;
    }

    // ─── 设计令牌注入 ───

    /** 获取关键设计令牌值（供区块在渲染时验证一致性） */
    public function tokens(): array
    {
        return [
            '--color-primary'   => defined('TOKEN_ACCENT_DEFAULT') ? TOKEN_ACCENT_DEFAULT : '#1E3A5F',
            '--surface-base'    => defined('TOKEN_SURFACE_BASE') ? TOKEN_SURFACE_BASE : '#f8fafc',
            '--surface-raised'  => defined('TOKEN_SURFACE_RAISED') ? TOKEN_SURFACE_RAISED : '#ffffff',
            '--content-primary' => defined('TOKEN_CONTENT_PRIMARY') ? TOKEN_CONTENT_PRIMARY : '#0f172a',
            '--accent-emphasis' => defined('TOKEN_ACCENT_EMPHASIS') ? TOKEN_ACCENT_EMPHASIS : '#2563EB',
            '--radius-md'       => defined('TOKEN_RADIUS_MD') ? TOKEN_RADIUS_MD : '14px',
            'version'           => defined('TOKENS_VERSION') ? TOKENS_VERSION : '3.0',
        ];
    }

    // ─── 调试 ───

    /** @return array{user:int|null, tenant:int|null, role:string|null, auth:bool} */
    public function toArray(): array
    {
        return [
            'user'   => $this->userId,
            'tenant' => $this->tenantId,
            'role'   => $this->role,
            'auth'   => $this->isAuthenticated,
        ];
    }
}
