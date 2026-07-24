<?php
/**
 * BulkImportUseCase — 批量站点导入
 *
 * 企业版: 支持 CSV/JSON 批量导入 A 站和 B 站配置。
 * 返回详细的导入报告（成功/跳过/失败）。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;
use RuntimeException;

final class BulkImportUseCase
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * 批量导入 A 站。
     *
     * @param int   $tenantId
     * @param array $sites  [['domain'=>'...', 'platform'=>'woocommerce'], ...]
     * @return array{imported: int, skipped: int, errors: array}
     */
    public function importASites(int $tenantId, array $sites): array
    {
        $imported = 0; $skipped = 0; $errors = [];

        foreach ($sites as $i => $site) {
            try {
                $domain = $site['domain'] ?? '';
                if (empty($domain)) {
                    $skipped++;
                    continue;
                }
                // 检查重复
                $stmt = $this->db->prepare('SELECT id FROM payment_router_a_sites WHERE tenant_id=? AND domain=?');
                $stmt->bind_param('is', $tenantId, $domain);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) { $skipped++; continue; }

                $platform = $site['platform'] ?? 'woocommerce';
                $apiKey = $site['api_key'] ?? ('ck_' . bin2hex(random_bytes(24)));
                $stmt2 = $this->db->prepare(
                    'INSERT INTO payment_router_a_sites (tenant_id, domain, platform, api_key) VALUES (?, ?, ?, ?)'
                );
                $stmt2->bind_param('isss', $tenantId, $domain, $platform, $apiKey);
                $stmt2->execute();
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row $i: {$e->getMessage()}";
            }
        }

        return compact('imported', 'skipped', 'errors');
    }

    /**
     * 批量导入 B 站。
     *
     * @param int   $tenantId
     * @param array $sites  [['domain'=>'...', 'payment_gateway'=>'paypal', 'weight'=>3, 'max_daily_orders'=>100], ...]
     * @return array{imported: int, skipped: int, errors: array}
     */
    public function importBSites(int $tenantId, array $sites): array
    {
        $imported = 0; $skipped = 0; $errors = [];

        foreach ($sites as $i => $site) {
            try {
                $domain = $site['domain'] ?? '';
                if (empty($domain)) { $skipped++; continue; }

                $stmt = $this->db->prepare('SELECT id FROM payment_router_b_sites WHERE tenant_id=? AND domain=?');
                $stmt->bind_param('is', $tenantId, $domain);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) { $skipped++; continue; }

                $gateway = $site['payment_gateway'] ?? 'paypal';
                $weight = (int)($site['weight'] ?? 1);
                $maxDaily = (int)($site['max_daily_orders'] ?? 50);
                $stmt2 = $this->db->prepare(
                    'INSERT INTO payment_router_b_sites (tenant_id, domain, payment_gateway, weight, max_daily_orders) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt2->bind_param('issii', $tenantId, $domain, $gateway, $weight, $maxDaily);
                $stmt2->execute();
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row $i: {$e->getMessage()}";
            }
        }

        return compact('imported', 'skipped', 'errors');
    }
}
