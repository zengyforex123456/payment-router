-- PaymentRouter: AB 轮询支付路由 — 核心中控数据库表
-- Phase 0: 核心中控引擎
-- 依赖: tenant_id 多租户隔离

CREATE TABLE IF NOT EXISTS payment_router_a_sites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    domain VARCHAR(255) NOT NULL,
    platform ENUM('woocommerce','opencart','magento','shopify') NOT NULL DEFAULT 'woocommerce',
    api_key VARCHAR(64) NOT NULL UNIQUE,
    webhook_url VARCHAR(512) NULL,
    status ENUM('active','paused') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_router_b_sites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    domain VARCHAR(255) NOT NULL,
    payment_gateway ENUM('paypal','stripe','square','other') NOT NULL,
    weight INT UNSIGNED NOT NULL DEFAULT 1,
    max_daily_orders INT UNSIGNED NOT NULL DEFAULT 50,
    status ENUM('active','cooling','disabled') NOT NULL DEFAULT 'active',
    cooled_until DATETIME NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    daily_order_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at DATETIME NULL,
    last_health_check DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_gateway (payment_gateway)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_router_order_mappings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    a_order_id VARCHAR(64) NOT NULL,
    b_order_id VARCHAR(64) NULL,
    a_site_id INT UNSIGNED NOT NULL,
    b_site_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    routing_reason TEXT NULL,
    dispatched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    INDEX idx_tenant_date (tenant_id, dispatched_at),
    INDEX idx_a_order (a_order_id),
    INDEX idx_b_order (b_order_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_router_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    event_type VARCHAR(50) NOT NULL,
    aggregate_id VARCHAR(64) NULL,
    payload JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_type (tenant_id, event_type),
    INDEX idx_aggregate (aggregate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
