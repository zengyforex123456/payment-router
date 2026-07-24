-- PaymentRouter SaaS: 租户策略配置 + 用量追踪
-- Phase P1: 入门版 SaaS

CREATE TABLE IF NOT EXISTS payment_router_tenant_config (
    tenant_id INT UNSIGNED NOT NULL PRIMARY KEY,
    tier ENUM('free','starter','pro','enterprise') NOT NULL DEFAULT 'free',
    strategy_name VARCHAR(50) NOT NULL DEFAULT 'balanced',
    routing_method VARCHAR(30) NOT NULL DEFAULT 'weighted',
    cooling_threshold INT UNSIGNED NOT NULL DEFAULT 3,
    cooldown_minutes INT UNSIGNED NOT NULL DEFAULT 30,
    custom_config JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 用量追踪 (每月1号通过 cron 重置)
CREATE TABLE IF NOT EXISTS payment_router_usage (
    tenant_id INT UNSIGNED NOT NULL,
    period VARCHAR(7) NOT NULL,
    dispatch_count INT UNSIGNED NOT NULL DEFAULT 0,
    paid_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (tenant_id, period),
    INDEX idx_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
