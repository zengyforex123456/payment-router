-- PaymentRouter: License 授权 + 更新日志

CREATE TABLE IF NOT EXISTS payment_router_licenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(32) NOT NULL UNIQUE,
    domain VARCHAR(255) NOT NULL,
    tier ENUM('community','starter','pro','enterprise') NOT NULL DEFAULT 'pro',
    issued_at DATE NOT NULL,
    expires_at DATE NOT NULL,
    signature VARCHAR(64) NOT NULL,
    status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_domain (domain),
    INDEX idx_status (status),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_router_updates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(16) NOT NULL,
    title VARCHAR(200) NOT NULL,
    changes JSON NOT NULL,
    is_security TINYINT(1) NOT NULL DEFAULT 0,
    min_tier ENUM('community','starter','pro','enterprise') NOT NULL DEFAULT 'community',
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    download_url VARCHAR(512) NULL,
    INDEX idx_published (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
