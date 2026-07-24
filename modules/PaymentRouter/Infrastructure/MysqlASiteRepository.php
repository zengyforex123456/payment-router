<?php
/**
 * MysqlASiteRepository — A 站 MySQL 仓储适配器
 *
 * 实现 ASiteRepositoryInterface，通过 DatabaseInterface 持久化。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Infrastructure;

use Converge\Contracts\DatabaseInterface;
use Converge\Modules\PaymentRouter\Domain\ASite;
use Converge\Modules\PaymentRouter\Domain\ASiteRepositoryInterface;

final class MysqlASiteRepository implements ASiteRepositoryInterface
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?ASite
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_router_a_sites WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByApiKey(string $apiKey): ?ASite
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_router_a_sites WHERE api_key = ?');
        $stmt->bind_param('s', $apiKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_router_a_sites WHERE tenant_id = ? ORDER BY created_at DESC');
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $result = $stmt->get_result();
        $sites = [];
        while ($row = $result->fetch_assoc()) {
            $sites[] = $this->hydrate($row);
        }
        return $sites;
    }

    public function save(ASite $site): void
    {
        $id = $site->id; $tid = $site->tenantId; $dom = $site->domain;
        $plat = $site->platform; $key = $site->apiKey; $st = $site->status;

        if ($site->id > 0) {
            $stmt = $this->db->prepare('UPDATE payment_router_a_sites SET domain=?, platform=?, status=? WHERE id=?');
            $stmt->bind_param('sssi', $dom, $plat, $st, $id);
        } else {
            $stmt = $this->db->prepare('INSERT INTO payment_router_a_sites (tenant_id, domain, platform, api_key, status) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('issss', $tid, $dom, $plat, $key, $st);
        }
        $stmt->execute();
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM payment_router_a_sites WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
    }

    private function hydrate(array $row): ASite
    {
        return new ASite(
            (int)$row['id'],
            (int)$row['tenant_id'],
            $row['domain'],
            $row['platform'],
            $row['api_key'],
            $row['status']
        );
    }
}
