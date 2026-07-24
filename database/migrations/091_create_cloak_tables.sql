-- PaymentRouter Cloak: 斗篷规则 + 访问日志

CREATE TABLE IF NOT EXISTS payment_router_cloak_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    field VARCHAR(20) NOT NULL,
    operator VARCHAR(20) NOT NULL,
    value VARCHAR(500) NOT NULL,
    action ENUM('safe','real','block') NOT NULL DEFAULT 'safe',
    priority INT UNSIGNED NOT NULL DEFAULT 100,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_enabled_priority (enabled, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 预置规则：Facebook/Google/TikTok 爬虫 → safe
INSERT IGNORE INTO payment_router_cloak_rules (field, operator, value, action, priority, enabled) VALUES
('user_agent', 'contains', 'facebookexternalhit', 'safe', 10, 1),
('user_agent', 'contains', 'Googlebot', 'safe', 10, 1),
('user_agent', 'contains', 'AdsBot-Google', 'safe', 10, 1),
('user_agent', 'contains', 'Bytespider', 'safe', 10, 1),
('user_agent', 'contains', 'TikTokSpider', 'safe', 10, 1),
('is_datacenter', 'equals', '1', 'safe', 20, 1),
('is_proxy', 'equals', '1', 'safe', 25, 1),
('user_agent', 'is_empty', '', 'safe', 30, 1),
('country', 'not_empty', '', 'real', 90, 1);

CREATE TABLE IF NOT EXISTS payment_router_cloak_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    action VARCHAR(10) NOT NULL,
    reason VARCHAR(200) NULL,
    ip_hash VARCHAR(64) NOT NULL,
    country VARCHAR(4) NULL,
    user_agent_short VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_action (tenant_id, action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
