-- PaymentRouter: 付款记录

CREATE TABLE IF NOT EXISTS payment_router_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    product_id VARCHAR(50) NOT NULL,
    tier VARCHAR(20) NOT NULL,
    amount INT UNSIGNED NOT NULL,
    currency VARCHAR(3) DEFAULT 'usd',
    channel VARCHAR(20) NOT NULL,
    tx_id VARCHAR(128) NOT NULL,
    status ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id),
    INDEX idx_tx (tx_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
