<?php
declare(strict_types=1);
namespace Converge\Foundation\System;

/**
 * TenantScope — 全局租户隔离中间件
 *
 * 商业价值: 1 个 Agency 租户 = 无限广告主子账户
 * 竞品 (Voluum/RedTrack) 按 Seat 收费 ($499/月/seat × 10 客户 = $4,990/月)
 * Converge 按 Tenant 收费 ($199/月 × 1 租户 = $199/月) — 成本是竞品的 1/25
 *
 * 数据库层:
 *   SELECT * FROM campaigns WHERE tenant_id = {current_tenant}
 *   Agency_Boss 看全局汇总, Client_Manager 看行级过滤
 *
 * 子用户权限: sub_user_role = 'admin' (所有数据) | 'manager' (指定 advertiser) | 'viewer' (只读)
 */
final class TenantScope
{
    private static ?int $currentTenantId = null;
    private static ?array $currentUser = null;

    /** 从 JWT/Session/Domain 自动解析当前租户 */
    public static function resolve(): int
    {
        if (self::$currentTenantId !== null) return self::$currentTenantId;

        // 1. Session (浏览器)
        if (!empty($_SESSION['tenant_id'])) {
            self::$currentTenantId = (int) $_SESSION['tenant_id'];
            self::$currentUser = $_SESSION['user'] ?? null;
            return self::$currentTenantId;
        }

        // 2. JWT Bearer Token (Flutter App / API)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            $payload = self::decodeJwt($token);
            if ($payload && isset($payload['tenant_id'])) {
                self::$currentTenantId = (int) $payload['tenant_id'];
                self::$currentUser = $payload;
                return self::$currentTenantId;
            }
        }

        // 3. 自定义域名 (白标 Agency)
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host) {
            $tenant = (new \mysqli(
                $_ENV['DB_HOST'] ?? 'localhost',
                $_ENV['DB_USER'] ?? 'root',
                $_ENV['DB_PASSWORD'] ?? '',
                $_ENV['DB_NAME'] ?? 'converge'
            ))->query(
                "SELECT id FROM tenants WHERE custom_domain = '" . (new \mysqli($_ENV['DB_HOST'] ?? 'localhost', $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASSWORD'] ?? '', $_ENV['DB_NAME'] ?? 'converge'))->real_escape_string($host) . "'"
            )->fetch_assoc();
            if ($tenant) {
                self::$currentTenantId = (int) $tenant['id'];
                return self::$currentTenantId;
            }
        }

        // 4. 自托管模式 — 无租户隔离
        if (($_ENV['DEPLOY_MODE'] ?? '') === 'self_hosted') return 0;

        return 0;
    }

    /** 获取当前租户 ID (0 = 自托管/无隔离) */
    public static function id(): int { return self::resolve(); }

    /** 设置当前租户 (CLI/Cron 使用) */
    public static function setTenant(int $id): void { self::$currentTenantId = $id; }

    /** 当前登录用户 */
    public static function user(): ?array { self::resolve(); return self::$currentUser; }

    /** 当前用户的子角色 */
    public static function subRole(): string { return self::$currentUser['sub_role'] ?? 'admin'; }

    /**
     * 行级权限过滤 — Sub-user 只能看指定广告主
     * 返回 SQL WHERE 片段
     *   admin:    1=1 (看全部)
     *   manager:  advertiser_id = 5 (只看自己的客户)
     *   viewer:   advertiser_id = 5 AND 禁止写入
     */
    public static function rowFilter(string $table = 'campaigns'): string
    {
        $user = self::user();
        $role = $user['sub_role'] ?? 'admin';
        $advertiserId = (int) ($user['advertiser_id'] ?? 0);

        return match ($role) {
            'admin' => '1=1',
            'manager', 'viewer' => ($advertiserId > 0)
                ? "{$table}.advertiser_id = $advertiserId"
                : "{$table}.tenant_id = " . self::id(),
            default => "{$table}.tenant_id = " . self::id(),
        };
    }

    /** 租户隔离 SQL 片段 */
    public static function where(string $table = 'campaigns'): string
    {
        $tid = self::id();
        if ($tid <= 0) return '1=1'; // 自托管模式
        return "{$table}.tenant_id = $tid";
    }

    /** 写入时自动注入 tenant_id */
    public static function inject(array &$data): void
    {
        $tid = self::id();
        if ($tid > 0 && !isset($data['tenant_id'])) {
            $data['tenant_id'] = $tid;
        }
    }

    /** 重置 (仅测试用) */
    public static function reset(): void
    {
        self::$currentTenantId = null;
        self::$currentUser = null;
    }

    // ═══ Private ═══

    private static function decodeJwt(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        // 验证过期
        if (isset($payload['exp']) && $payload['exp'] < time()) return null;
        return $payload ?: null;
    }
}
