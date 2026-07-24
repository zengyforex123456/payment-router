-- PaymentRouter Cloak: 行为追踪 + 动态内容

CREATE TABLE IF NOT EXISTS payment_router_cloak_behavior (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    stay_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    scroll_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    has_conversion TINYINT(1) NOT NULL DEFAULT 0,
    risk_score TINYINT NOT NULL DEFAULT 0,
    disposition VARCHAR(20) NOT NULL DEFAULT 'real',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_router_dcd_mappings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    risky_word VARCHAR(100) NOT NULL,
    safe_word VARCHAR(100) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 预置映射
INSERT IGNORE INTO payment_router_dcd_mappings (risky_word, safe_word, enabled) VALUES
('仿牌','正品',1),('F牌','精品',1),('复刻','定制',1),('1:1','高品质',1),
('减肥药','保健品',1),('电子烟','香薰机',1),('成人用品','个人护理',1);
