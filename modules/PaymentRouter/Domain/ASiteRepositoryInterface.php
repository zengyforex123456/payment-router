<?php
/**
 * ASiteRepositoryInterface — A 站数据端口
 *
 * ≤5 方法，只依赖 Domain 实体，不含 IO。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Domain;

interface ASiteRepositoryInterface
{
    /** 按 ID 查找 */
    public function findById(int $id): ?ASite;

    /** 按 API Key 查找（用于外部 API 认证） */
    public function findByApiKey(string $apiKey): ?ASite;

    /** 列出某租户所有 A 站 */
    public function findByTenant(int $tenantId): array;

    /** 保存（创建或更新） */
    public function save(ASite $site): void;

    /** 删除 */
    public function delete(int $id): void;
}
