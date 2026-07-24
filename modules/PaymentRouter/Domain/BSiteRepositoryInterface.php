<?php
/**
 * BSiteRepositoryInterface — B 站数据端口
 *
 * ≤5 方法，只依赖 Domain 实体，不含 IO。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Domain;

interface BSiteRepositoryInterface
{
    /** 按 ID 查找 */
    public function findById(int $id): ?BSite;

    /** 列出某租户下可用的 B 站（active + 未冷却 + 未达日上限） */
    public function findAvailable(int $tenantId): array;

    /** 列出某租户所有 B 站 */
    public function findByTenant(int $tenantId): array;

    /** 保存（创建或更新） */
    public function save(BSite $site): void;

    /** 重置所有 B 站的每日订单计数（定时任务调用） */
    public function resetDailyCounts(int $tenantId): void;
}
