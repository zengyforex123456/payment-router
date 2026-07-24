<?php
declare(strict_types=1);

// APP_ROOT — 应用根目录 (data/source/)，所有路径引用的唯一锚点
// 替代 __DIR__ . '/../' 模式，未来移动目录只需改这一行
define('APP_ROOT', dirname(__DIR__));

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'converge');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_unicode_ci');

define('BASE_URL', getenv('BASE_URL') ?: '');
define('PUBLIC_WEB_PREFIX', getenv('PUBLIC_WEB_PREFIX') ?: '');
define('ASSETS_BASE_URL', getenv('ASSETS_BASE_URL') ?: '');
define('APP_BASE_URL', getenv('APP_BASE_URL') ?: '');
define('APP_KEY', 'converge-dev-key-64chars-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', true);
define('DEPLOY_MODE', 'self_hosted'); // self_hosted | saas | enterprise
define('SINGLE_ADMIN_MODE', true);

define('APP_TIMEZONE', 'Asia/Shanghai');
define('SESSION_LIFETIME', 7200);
define('SESSION_COOKIE_HTTPONLY', true);
// SESSION_COOKIE_SECURE defined below with other security flags

// v2.0 Session — handler + GC. Default 'files' (no change). 设 'database' 用 DB 驱动 (多节点共享)。
define('SESSION_HANDLER', getenv('SESSION_HANDLER') ?: 'files'); // files | database
define('SESSION_GC_PROBABILITY', 1);   // 1/100 chance per request (PHP default)
define('SESSION_GC_DIVISOR', 100);     // GC divisor (PHP default)

define('HASH_ALGO', PASSWORD_ARGON2ID);
define('HASH_OPTIONS', ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2]);

define('ROOT_PATH', dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('LOGS_PATH', STORAGE_PATH . '/logs');
define('CACHE_PATH', STORAGE_PATH . '/cache');

// v2.0 Observability
define('EVENTSTORE_PATH', STORAGE_PATH . '/eventstore.db');
define('LOG_LEVEL', 'debug');
define('LOG_RETENTION_DAYS', 7);

// v2.0 Resilience
define('CIRCUIT_BREAKER_THRESHOLD', 3);
define('CIRCUIT_BREAKER_TIMEOUT', 30);

// v2.0 Security — 默认关闭。上线设 APP_ENV=production 全部自动开启。
// 单独开关: LOGIN_RATE_LIMIT_ENABLED=true · SSRF_GUARD_ENABLED=true 等
define('SECURITY_PRODUCTION_MODE', getenv('APP_ENV') === 'production' || getenv('SECURITY_ENABLED') === '1');
define('LOGIN_RATE_LIMIT_ENABLED', SECURITY_PRODUCTION_MODE && getenv('LOGIN_RATE_LIMIT_ENABLED') !== 'false');
define('SSRF_GUARD_ENABLED', SECURITY_PRODUCTION_MODE && getenv('SSRF_GUARD_ENABLED') !== 'false');
define('SECURITY_HEADERS_ENABLED', SECURITY_PRODUCTION_MODE && getenv('SECURITY_HEADERS_ENABLED') !== 'false');
define('SESSION_COOKIE_SECURE', SECURITY_PRODUCTION_MODE && getenv('SESSION_COOKIE_SECURE') !== 'false');

// v2.0 HTTPS enforcement — 默认关闭。生产环境自动开启(外部CDN/Cloudflare已终止SSL则设 false)。
define('HTTPS_ENFORCED', SECURITY_PRODUCTION_MODE && getenv('HTTPS_ENFORCED') !== 'false');

// v2.0 SMTP — email delivery (PHPMailer). Leave blank for PHP mail() fallback.
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: '587'));
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_FROM', getenv('SMTP_FROM') ?: '');
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls');

// v2.0 CORS — API v1 allowed origin. Empty = '*'. Set to dashboard domain in production.
define('CORS_ORIGIN', getenv('CORS_ORIGIN') ?: '');

// v2.0 GeoIP
define('GEOIP_CACHE_ENABLED', false); // Disabled for local dev
define('GEOIP_DATABASE_PATH', STORAGE_PATH . '/GeoLite2-City.mmdb'); // GeoIP 库路径

// Start time for uptime
define('APP_START_TIME', microtime(true));

define('INSTALLED', true);

// Precision Skin — 数据源: true=Demo数据 false=真实数据库
define('PRECISION_MOCK_DATA', getenv('PRECISION_MOCK_DATA') !== '0');

// ── Payment: Stripe (env-injected, NEVER hardcode secrets) ──
define('STRIPE_API_KEY', getenv('STRIPE_API_KEY') ?: '');
define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: '');
define('BILLING_SUCCESS_URL', (getenv('APP_URL') ?: 'http://localhost:8080') . '/index.php?page=billing&paid=1');
define('BILLING_CANCEL_URL', (getenv('APP_URL') ?: 'http://localhost:8080') . '/upgrade.php?canceled=1');

// ── Payment: Cryptomus (USDT/crypto, env-injected) ──
define('CRYPTOMUS_API_KEY', getenv('CRYPTOMUS_API_KEY') ?: '');
define('CRYPTOMUS_MERCHANT_ID', getenv('CRYPTOMUS_MERCHANT_ID') ?: '');
define('CRYPTOMUS_WEBHOOK_URL', (getenv('APP_URL') ?: 'http://localhost:8080') . '/api-billing-webhook.php?provider=cryptomus');

// 佣金批量代付开关 — 默认关(需 Cryptomus 客户经理开通 mass payout + operator 确认)
define('CRYPTOMUS_PAYOUT_ENABLED', getenv('CRYPTOMUS_PAYOUT_ENABLED') === '1');
