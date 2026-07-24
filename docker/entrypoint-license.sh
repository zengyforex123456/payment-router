#!/bin/bash
# ═══════════════════════════════════════════════════════
# Docker Entrypoint — License Bootstrap Sequence
# ═══════════════════════════════════════════════════════
# 容器启动时执行，在 PHP-FPM 之前运行。
# 如果激活失败，容器不会启动 PHP，防止无授权运行。
#
# 用法 (Dockerfile):
#   COPY docker/entrypoint-license.sh /entrypoint.sh
#   RUN chmod +x /entrypoint.sh
#   ENTRYPOINT ["/entrypoint.sh"]
# ═══════════════════════════════════════════════════════

set -e

echo "╔══════════════════════════════════════╗"
echo "║  Converge License Bootstrap          ║"
echo "╚══════════════════════════════════════╝"

# 环境变量检查
: ${CONVERGE_CLIENT_ID:?Must set CONVERGE_CLIENT_ID}
: ${CONVERGE_LICENSE_KEY:?Must set CONVERGE_LICENSE_KEY}
: ${CONVERGE_AUTH_SERVER:?Must set CONVERGE_AUTH_SERVER}

echo "[Bootstrap] Client: ${CONVERGE_CLIENT_ID}"
echo "[Bootstrap] Server: ${CONVERGE_AUTH_SERVER}"

# 第一步: PHP 启动前验证 License
# 调用 Bootstrap PHP 脚本 (内嵌，不依赖框架)
ACTIVATION_RESULT=$(php -r '
require "/var/www/converge/app/Foundation/License/LicenseBootstrap.php";
try {
    $bs = new \Converge\Foundation\License\LicenseBootstrap(
        getenv("CONVERGE_AUTH_SERVER"),
        getenv("CONVERGE_CLIENT_ID"),
        getenv("CONVERGE_LICENSE_KEY")
    );
    $key = $bs->bootstrap();
    echo "ACTIVATED:" . $key;
} catch (\Converge\Foundation\License\LicenseException $e) {
    echo "FATAL:" . $e->getMessage();
    exit(1);
}
' 2>&1)

if [[ "$ACTIVATION_RESULT" == FATAL:* ]]; then
    echo "╔══════════════════════════════════════╗"
    echo "║  ❌ LICENSE ACTIVATION FAILED         ║"
    echo "║  ${ACTIVATION_RESULT#FATAL:}          ║"
    echo "║  Contact support@converge.io          ║"
    echo "╚══════════════════════════════════════╝"
    exit 1
fi

# 第二步: 导出解密密钥到环境变量 (PHP-FPM 读取)
export CONVERGE_DECRYPT_KEY="${ACTIVATION_RESULT#ACTIVATED:}"
echo "[Bootstrap] ✅ License activated. Key loaded into memory."

# 第三步: 启动 cron 续签 (每 6 小时)
(while true; do
    sleep 21600  # 6 hours
    echo "[Renew] Running JWT renewal..."
    php -r '
    require "/var/www/converge/app/Foundation/License/LicenseBootstrap.php";
    $bs = new \Converge\Foundation\License\LicenseBootstrap();
    $bs->renewIfNeeded();
    ' 2>&1 || echo "[Renew] Renewal failed (will retry in 6h)"
done) &
RENEW_PID=$!
echo "[Bootstrap] Renewal daemon started (PID: $RENEW_PID, interval: 6h)"

# 第四步: 启动 PHP-FPM (正常业务流量)
echo "[Bootstrap] Starting PHP-FPM..."
exec "$@"
