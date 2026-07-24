<?php
/**
 * AB Payment Router — OpenCart B-Site Model
 *
 * 管理 B-Order-Ref ↔ OC Order ID 映射。
 * 放在 catalog/model/extension/payment/ab_router.php
 */
class ModelExtensionPaymentAbRouter extends Model
{
    /**
     * 保存 B-Order-Ref 到 OC Order ID 的映射。
     *
     * @param int    $orderId   OpenCart 订单 ID
     * @param string $bOrderRef B 站订单引用 (B-XXXX)
     */
    public function saveOrderRef(int $orderId, string $bOrderRef): void
    {
        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "ab_router_orders` SET
             `order_id` = " . (int)$orderId . ",
             `b_order_ref` = '" . $this->db->escape($bOrderRef) . "',
             `created_at` = NOW()"
        );
    }

    /**
     * 通过 B-Order-Ref 查找 OC Order ID（用于防重放）。
     *
     * @param string $bOrderRef
     * @return int|null
     */
    public function findOrderByRef(string $bOrderRef): ?int
    {
        $query = $this->db->query(
            "SELECT `order_id` FROM `" . DB_PREFIX . "ab_router_orders`
             WHERE `b_order_ref` = '" . $this->db->escape($bOrderRef) . "'
             LIMIT 1"
        );
        return $query->num_rows ? (int)$query->row['order_id'] : null;
    }

    /**
     * 通过 OC Order ID 获取 B-Order-Ref。
     *
     * @param int $orderId
     * @return string|null
     */
    public function getOrderRef(int $orderId): ?string
    {
        $query = $this->db->query(
            "SELECT `b_order_ref` FROM `" . DB_PREFIX . "ab_router_orders`
             WHERE `order_id` = " . (int)$orderId . "
             LIMIT 1"
        );
        return $query->num_rows ? $query->row['b_order_ref'] : null;
    }

    /**
     * 安装模型时创建映射表。
     */
    public function install(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ab_router_orders` (
              `order_id` INT(11) NOT NULL,
              `b_order_ref` VARCHAR(64) NOT NULL,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`order_id`),
              UNIQUE KEY `b_order_ref` (`b_order_ref`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * 卸载模型时删除映射表。
     */
    public function uninstall(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "ab_router_orders`");
    }
}
