<?php
/**
 * MysqlBSiteRepository — B 站 MySQL 仓储适配器
 *
 * 实现 BSiteRepositoryInterface。
 * findAvailable() 是核心查询——过滤出可接收订单的 B 站。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Infrastructure;

use Converge\Contracts\DatabaseInterface;
use Converge\Modules\PaymentRouter\Domain\BSite;
use Converge\Modules\PaymentRouter\Domain\BSiteRepositoryInterface;

final class MysqlBSiteRepository implements BSiteRepositoryInterface
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?BSite
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_router_b_sites WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ? $this->hydrate($row) : null;
    }

    public function findAvailable(int $tenantId): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'SELECT * FROM payment_router_b_sites
             WHERE tenant_id = ? AND status = \'active\'
               AND (cooled_until IS NULL OR cooled_until < ?)
               AND daily_order_count < max_daily_orders
             ORDER BY weight DESC'
        );
        $stmt->bind_param('is', $tenantId, $now);
        $stmt->execute();
        $result = $stmt->get_result();
        $sites = [];
        while ($row = $result->fetch_assoc()) {
            $sites[] = $this->hydrate($row);
        }
        return $sites;
    }

    public function findByTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_router_b_sites WHERE tenant_id = ? ORDER BY created_at DESC');
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $result = $stmt->get_result();
        $sites = [];
        while ($row = $result->fetch_assoc()) {
            $sites[] = $this->hydrate($row);
        }
        return $sites;
    }

    public function save(BSite $site): BSite
    {
        $id = $site->id; $tid = $site->tenantId; $dom = $site->domain;
        $gw = $site->paymentGateway; $w = $site->weight; $md = $site->maxDailyOrders;
        $st = $site->status; $cu = $site->cooledUntil;
        $cf = $site->consecutiveFailures; $dc = $site->dailyOrderCount;

        if ($site->id > 0) {
            $sql = 'UPDATE payment_router_b_sites SET domain=?, payment_gateway=?, weight=?, max_daily_orders=?, status=?, consecutive_failures=?, daily_order_count=?';
            $params = [$dom, $gw, $w, $md, $st, $cf, $dc]; $types = 'ssiisii';
            if ($cu !== null) { $sql .= ', cooled_until=?'; $types .= 's'; $params[] = $cu; }
            else { $sql .= ', cooled_until=NULL'; }
            $sql .= ' WHERE id=?'; $types .= 'i'; $params[] = $id;
            $stmt = $this->db->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
            return $site;
        }
        $stmt = $this->db->prepare('INSERT INTO payment_router_b_sites (tenant_id, domain, payment_gateway, weight, max_daily_orders, status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issiis', $tid, $dom, $gw, $w, $md, $st); $stmt->execute();
        return new BSite($this->db->lastInsertId(), $tid, $dom, $gw, $w, $md, $st);
    }

    public function resetDailyCounts(int $tenantId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE payment_router_b_sites SET daily_order_count = 0 WHERE tenant_id = ?'
        );
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
    }

    private function hydrate(array $row): BSite
    {
        return new BSite(
            (int)$row['id'],
            (int)$row['tenant_id'],
            $row['domain'],
            $row['payment_gateway'],
            (int)$row['weight'],
            (int)$row['max_daily_orders'],
            $row['status'],
            $row['cooled_until'],
            (int)$row['consecutive_failures'],
            (int)$row['daily_order_count']
        );
    }
}
