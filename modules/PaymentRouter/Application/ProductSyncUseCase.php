<?php
/**
 * ProductSyncUseCase — A 站→B 站商品同步
 *
 * P1: A 站商品更新时，自动推送到所有关联 B 站。
 * B 站需保持与 A 站相似的商品目录以避免支付平台风控标记。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;
use RuntimeException;

final class ProductSyncUseCase
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * 将商品推送到租户的所有 B 站。
     *
     * @param int   $tenantId
     * @param array $product {name, sku_prefix, price, description, image_url, category}
     * @return array{product_ref: string, synced_to: int, b_sites: array}
     */
    public function push(int $tenantId, array $product): array
    {
        $productRef = 'SYNC-' . strtoupper(bin2hex(random_bytes(4)));

        // 获取所有 active B 站
        $stmt = $this->db->prepare(
            "SELECT id, domain FROM payment_router_b_sites WHERE tenant_id = ? AND status != 'disabled'"
        );
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $bSites = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $results = [];
        foreach ($bSites as $bs) {
            try {
                $success = $this->pushToBSite($bs['domain'], $product);
                $results[] = [
                    'b_site_id' => (int)$bs['id'],
                    'domain'    => $bs['domain'],
                    'success'   => $success,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'b_site_id' => (int)$bs['id'],
                    'domain'    => $bs['domain'],
                    'success'   => false,
                    'error'     => $e->getMessage(),
                ];
            }
        }

        // 记录同步日志
        $stmt2 = $this->db->prepare(
            "INSERT INTO payment_router_product_syncs (tenant_id, product_ref, product_data, b_sites_count, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $json = json_encode($product, JSON_UNESCAPED_UNICODE);
        $count = count($bSites);
        $stmt2->bind_param('issi', $tenantId, $productRef, $json, $count);
        $stmt2->execute();

        return [
            'product_ref' => $productRef,
            'synced_to'   => count(array_filter($results, fn($r) => $r['success'])),
            'b_sites'     => $results,
        ];
    }

    /**
     * 拉取同步历史。
     */
    public function history(int $tenantId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM payment_router_product_syncs WHERE tenant_id = ? ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->bind_param('ii', $tenantId, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * 推送到单个 B 站 (OpenCart API)。
     */
    private function pushToBSite(string $domain, array $product): bool
    {
        $url = "https://{$domain}/index.php?route=api/product/add";
        $data = [
            'name'        => $product['name'] ?? 'Product',
            'model'       => ($product['sku_prefix'] ?? 'SYNC') . '-' . random_int(1000, 9999),
            'price'       => $product['price'] ?? '9.99',
            'description' => $product['description'] ?? '',
            'image_url'   => $product['image_url'] ?? '',
            'category'    => $product['category'] ?? 'General',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }
}
