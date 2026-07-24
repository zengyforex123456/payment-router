<?php
/**
 * MysqlOrderMappingRepository — 订单映射 MySQL 仓储适配器
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Infrastructure;

use Converge\Contracts\DatabaseInterface;
use Converge\Modules\PaymentRouter\Domain\OrderMapping;
use Converge\Modules\PaymentRouter\Domain\OrderMappingRepositoryInterface;

final class MysqlOrderMappingRepository implements OrderMappingRepositoryInterface
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?OrderMapping
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_router_order_mappings WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByAOrderId(string $aOrderId): ?OrderMapping
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_router_order_mappings WHERE a_order_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('s', $aOrderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByBOrderId(string $bOrderId): ?OrderMapping
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_router_order_mappings WHERE b_order_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('s', $bOrderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByTenant(int $tenantId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payment_router_order_mappings
             WHERE tenant_id = ?
             ORDER BY dispatched_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('iii', $tenantId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $mappings = [];
        while ($row = $result->fetch_assoc()) {
            $mappings[] = $this->hydrate($row);
        }
        return $mappings;
    }

    public function save(OrderMapping $mapping): void
    {
        $id = $mapping->id; $tid = $mapping->tenantId; $ao = $mapping->aOrderId;
        $bo = $mapping->bOrderId; $as = $mapping->aSiteId; $bs = $mapping->bSiteId;
        $amt = $mapping->amount; $cur = $mapping->currency; $st = $mapping->status;
        $rr = $mapping->routingReason; $pa = $mapping->paidAt;

        if ($mapping->id > 0) {
            $stmt = $this->db->prepare('UPDATE payment_router_order_mappings SET b_order_id=?, status=?, paid_at=? WHERE id=?');
            $stmt->bind_param('sssi', $bo, $st, $pa, $id);
        } else {
            $stmt = $this->db->prepare('INSERT INTO payment_router_order_mappings (tenant_id, a_order_id, b_order_id, a_site_id, b_site_id, amount, currency, status, routing_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('issiidsss', $tid, $ao, $bo, $as, $bs, $amt, $cur, $st, $rr);
        }
        if (!$stmt->execute()) {
            throw new \RuntimeException('DB: ' . $stmt->error . ' [status=' . var_export($st, true) . ']');
        }
    }

    private function hydrate(array $row): OrderMapping
    {
        return new OrderMapping(
            (int)$row['id'],
            (int)$row['tenant_id'],
            $row['a_order_id'],
            $row['b_order_id'],
            (int)$row['a_site_id'],
            (int)$row['b_site_id'],
            $row['amount'],
            $row['currency'],
            $row['status'],
            $row['routing_reason'],
            $row['dispatched_at'],
            $row['paid_at']
        );
    }
}
