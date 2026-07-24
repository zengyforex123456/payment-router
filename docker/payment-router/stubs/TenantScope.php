<?php
/**
 * TenantScope 桩 — 独立运行时最小实现
 *
 * 独立部署时始终返回 0 (self-hosted mode, 无租户隔离)。
 * 正式环境由 kernel/src/Foundation/System/TenantScope.php 提供。
 */
declare(strict_types=1);

namespace Converge\Foundation\System;

final class TenantScope
{
    private static int $tenantId = 0;

    /** 解析当前租户 (独立模式恒为 0) */
    public static function resolve(): void
    {
        self::$tenantId = 0;
    }

    /** 获取当前租户 ID */
    public static function id(): int
    {
        return self::$tenantId;
    }

    /** 生成 SQL WHERE 片段 */
    public static function where(string $table): string
    {
        return "{$table}.tenant_id = " . self::$tenantId;
    }
}
