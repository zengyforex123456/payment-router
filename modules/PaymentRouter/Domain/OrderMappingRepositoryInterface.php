<?php
/**
 * OrderMappingRepositoryInterface — 订单映射数据端口
 *
 * ≤5 方法，只依赖 Domain 实体，不含 IO。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Domain;

interface OrderMappingRepositoryInterface
{
    /** 按 ID 查找 */
    public function findById(int $id): ?OrderMapping;

    /** 按 A 站订单号查找 */
    public function findByAOrderId(string $aOrderId): ?OrderMapping;

    /** 按 B 站订单号查找 */
    public function findByBOrderId(string $bOrderId): ?OrderMapping;

    /** 列出某租户的映射记录（分页） */
    public function findByTenant(int $tenantId, int $limit = 50, int $offset = 0): array;

    /** 保存，返回带真实 ID 的实体 */
    public function save(OrderMapping $mapping): OrderMapping;
}
