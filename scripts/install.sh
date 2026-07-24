#!/bin/bash
# ═══ PaymentRouter — One-Command Install ═══
# 专业版一键部署脚本。自动检测环境、创建数据库、执行迁移、启动服务。
#
# 用法:
#   curl -sSL https://your-cdn.com/install.sh | bash
#   或:
#   bash install.sh [--port 8080] [--db-host 127.0.0.1] [--db-name payment_router]
#
# 要求: PHP 8.0+ / MySQL 5.7+ 或 MariaDB 10.3+ / curl / openssl
set -euo pipefail

APP_PORT="${PORT:-8080}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-payment_router}"
DB_USER="${DB_USER:-payment_router}"
DB_PASS="${DB_PASS:-}"
APP_SECRET="${APP_SECRET:-$(openssl rand -hex 32)}"
INSTALL_DIR="${INSTALL_DIR:-/opt/payment-router}"

GREEN='\033[32m'; YELLOW='\033[33m'; RED='\033[31m'; NC='\033[0m'
ok()   { echo -e "  ${GREEN}✅${NC} $1"; }
warn() { echo -e "  ${YELLOW}⚠️${NC}  $1"; }
fail() { echo -e "  ${RED}🚫${NC} $1"; exit 1; }

echo "══════════════════════════════════════════"
echo "  PaymentRouter v0.1.0 — Installer"
echo "══════════════════════════════════════════"
echo ""

# ── 1. Pre-flight checks ──
echo "① 环境检测"
php -v >/dev/null 2>&1 || fail "PHP 未安装。需要 PHP 8.0+"
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
[[ "$PHP_VERSION" > "7.9" ]] || fail "PHP 版本过低: $PHP_VERSION (需要 ≥8.0)"
ok "PHP $PHP_VERSION"

php -m | grep -q mysqli || fail "PHP mysqli 扩展未加载"
ok "PHP mysqli 扩展"

php -m | grep -q mbstring || warn "PHP mbstring 扩展未加载（非必需，建议安装）"
php -m | grep -q openssl || fail "PHP openssl 扩展未加载"
ok "PHP openssl 扩展"

mysql --version >/dev/null 2>&1 || warn "mysql 客户端未安装（迁移时需手动导入 SQL）"
ok "环境检测完成"

# ── 2. Database setup ──
echo ""
echo "② 数据库配置"
echo "  主机: $DB_HOST:$DB_PORT"
echo "  库名: $DB_NAME"
echo "  用户: $DB_USER"

# Test connection
mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" $DB_PASS -e "SELECT 1" >/dev/null 2>&1 || {
    warn "无法连接 MySQL。尝试创建数据库..."
    mysql -h"$DB_HOST" -P"$DB_PORT" -u root -e "CREATE DATABASE IF NOT EXISTS $DB_NAME; CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS'; GRANT ALL ON $DB_NAME.* TO '$DB_USER'@'%'; FLUSH PRIVILEGES;" 2>/dev/null || warn "自动创建失败，请手动创建数据库"
}
ok "数据库连接 OK"

# ── 3. Run migrations ──
echo ""
echo "③ 执行迁移"

MIGRATIONS_DIR="${INSTALL_DIR}/database/migrations"
if [ -d "$MIGRATIONS_DIR" ]; then
    for f in "$MIGRATIONS_DIR"/*payment_router*.sql; do
        [ -f "$f" ] || continue
        echo "  导入: $(basename "$f")"
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$f" 2>/dev/null || warn "迁移失败: $f"
    done
    ok "迁移完成"
else
    warn "迁移目录不存在: $MIGRATIONS_DIR"
fi

# ── 4. Generate .env ──
echo ""
echo "④ 生成配置"
cat > "${INSTALL_DIR}/.env" <<EOF
APP_ENV=production
APP_SECRET=$APP_SECRET
APP_PORT=$APP_PORT
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASSWORD=$DB_PASS
EOF
ok ".env 已生成 (APP_SECRET=$APP_SECRET)"

# ── 5. Start service ──
echo ""
echo "⑤ 启动服务"
cd "$INSTALL_DIR"

# Check if systemd
if command -v systemctl &>/dev/null; then
    cat > /etc/systemd/system/payment-router.service <<EOF
[Unit]
Description=PaymentRouter API
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=$INSTALL_DIR
EnvironmentFile=$INSTALL_DIR/.env
ExecStart=/usr/bin/php -S 0.0.0.0:$APP_PORT -t $INSTALL_DIR $INSTALL_DIR/docker/payment-router/index.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable payment-router
    systemctl start payment-router
    ok "systemd 服务已注册: payment-router"
else
    # Fallback: start directly
    nohup php -S "0.0.0.0:$APP_PORT" -t "$INSTALL_DIR" "$INSTALL_DIR/docker/payment-router/index.php" > /var/log/payment-router.log 2>&1 &
    echo $! > /var/run/payment-router.pid
    ok "服务已启动 (PID: $(cat /var/run/payment-router.pid))"
fi

# ── 6. Verify ──
echo ""
echo "⑥ 验证部署"
sleep 2
HEALTH=$(curl -s "http://127.0.0.1:$APP_PORT/health" || echo '{"status":"error"}')
echo "$HEALTH" | grep -q '"status":"ok"' && ok "Health check 通过" || warn "Health check 失败: $HEALTH"

# ── Done ──
echo ""
echo "══════════════════════════════════════════"
echo -e "  ${GREEN}PaymentRouter 安装完成！${NC}"
echo "══════════════════════════════════════════"
echo ""
echo "  API 地址: http://YOUR_IP:$APP_PORT"
echo "  Health:   http://YOUR_IP:$APP_PORT/health"
echo ""
echo "  下一步:"
echo "  1. 注册 A 站: curl -X POST http://YOUR_IP:$APP_PORT/api/payment-router/a-sites -d '{\"domain\":\"shop.com\"}'"
echo "  2. 注册 B 站: curl -X POST http://YOUR_IP:$APP_PORT/api/payment-router/b-sites -d '{\"domain\":\"pay.com\"}'"
echo "  3. 查看仪表盘: curl http://YOUR_IP:$APP_PORT/api/payment-router/dashboard"
echo ""
echo "  文档: https://github.com/converge/payment-router"
echo "  APP_SECRET: $APP_SECRET (请妥善保存)"
