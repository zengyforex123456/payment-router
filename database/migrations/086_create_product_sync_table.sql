-- PaymentRouter: 商品同步记录 + 对账日志

CREATE TABLE IF NOT EXISTS payment_router_product_syncs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    product_ref VARCHAR(16) NOT NULL,
    product_data JSON NOT NULL,
    b_sites_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_router_reconciliations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    checked INT UNSIGNED NOT NULL DEFAULT 0,
    mismatches INT UNSIGNED NOT NULL DEFAULT 0,
    fixed INT UNSIGNED NOT NULL DEFAULT 0,
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_date (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
