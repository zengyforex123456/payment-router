#!/bin/bash
# ═══ PaymentRouter — 一键部署脚本 ═══
# 用法: bash scripts/deploy.sh <项目名> <域名> <服务器IP> [SSH用户]
# 示例: bash scripts/deploy.sh myproject paymentrouter.vip 137.184.225.93 root
#
# 自动完成:
#  ① 打包项目 → ② 上传服务器 → ③ 创建数据库 → ④ 运行迁移
#  ⑤ 配置 Nginx 多域名 → ⑥ 启动 Docker → ⑦ 验证部署
set -e

PROJECT="${1:?Usage: deploy.sh <project-name> <domain> <server-ip> [ssh-user]}"
DOMAIN="${2:?Missing domain}"
SERVER="${3:?Missing server IP}"
SSH_USER="${4:-root}"
PROJECT_DIR="/opt/${PROJECT}"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)

GREEN='\033[32m'; YELLOW='\033[33m'; NC='\033[0m'
ok() { echo -e "  ${GREEN}✅${NC} $1"; }

echo "══════════════════════════════════════════"
echo "  PaymentRouter Deploy — ${PROJECT}"
echo "  Domain: ${DOMAIN} | Server: ${SSH_USER}@${SERVER}"
echo "══════════════════════════════════════════"
echo ""

# ── ① 打包 ──
echo "① 打包项目..."
PACKAGE="/tmp/${PROJECT}-${TIMESTAMP}.tar.gz"
tar --exclude='node_modules' --exclude='.git' --exclude='*.zip' \
  -czf "$PACKAGE" -C "$(dirname "$0")/.." .
ok "Package: $(du -h "$PACKAGE" | cut -f1)"

# ── ② 上传 ──
echo "② 上传到 ${SERVER}..."
ssh "${SSH_USER}@${SERVER}" "mkdir -p ${PROJECT_DIR}"
scp "$PACKAGE" "${SSH_USER}@${SERVER}:${PROJECT_DIR}/"
ssh "${SSH_USER}@${SERVER}" "cd ${PROJECT_DIR} && tar xzf $(basename "$PACKAGE") && rm $(basename "$PACKAGE")"
ok "Files uploaded to ${PROJECT_DIR}"

# ── ③ 数据库 ──
echo "③ 创建数据库..."
DB_NAME="${PROJECT//-/_}"
ssh "${SSH_USER}@${SERVER}" "
  docker exec converge-mysql-1 mysql -u root -pchange-me-to-a-secure-password \
    -e \"CREATE DATABASE IF NOT EXISTS ${DB_NAME};\" 2>/dev/null
  for f in ${PROJECT_DIR}/database/migrations/*payment_router*.sql ${PROJECT_DIR}/database/migrations/*cloak*.sql ${PROJECT_DIR}/database/migrations/*paddle*.sql; do
    [ -f \"\$f\" ] && docker exec -i converge-mysql-1 mysql -u root -pchange-me-to-a-secure-password ${DB_NAME} < \"\$f\" 2>/dev/null && echo \"  \$(basename \$f)\"
  done
"
ok "Database: ${DB_NAME}"

# ── ④ 生成 Nginx 配置 ──
echo "④ 配置 Nginx (${DOMAIN})..."
NGINX_CONF="/tmp/${PROJECT}-nginx.conf"
cat > "$NGINX_CONF" <<NGINX
server {
    listen 80;
    server_name ${DOMAIN} www.${DOMAIN};

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX

scp "$NGINX_CONF" "${SSH_USER}@${SERVER}:${PROJECT_DIR}/nginx.conf"
ssh "${SSH_USER}@${SERVER}" "
  docker cp ${PROJECT_DIR}/nginx.conf converge-app-1:/etc/nginx/conf.d/${PROJECT}.conf 2>/dev/null
  docker exec converge-app-1 nginx -t && docker exec converge-app-1 nginx -s reload
"
ok "Nginx configured: ${DOMAIN} → port 8080"

# ── ⑤ 启动 ──
echo "⑤ 启动 PaymentRouter..."
ssh "${SSH_USER}@${SERVER}" "
  docker rm -f ${PROJECT} 2>/dev/null || true
  docker run -d --name ${PROJECT} --network converge_default --restart unless-stopped \
    -v ${PROJECT_DIR}:/var/www \
    -e DB_HOST=mysql -e DB_PORT=3306 -e DB_NAME=${DB_NAME} \
    -e DB_USER=root -e DB_PASSWORD=change-me-to-a-secure-password \
    -e APP_SECRET=\$(openssl rand -hex 16) \
    -e APP_URL=https://${DOMAIN} \
    php:8.2-cli sh -c 'docker-php-ext-install mysqli 2>/dev/null && exec php -S 0.0.0.0:8080 -t /var/www /var/www/docker/payment-router/index.php'
  sleep 5
"
ok "Container: ${PROJECT} (port 8080)"

# ── ⑥ 验证 ──
echo "⑥ 验证部署..."
if curl -s -o /dev/null -w "%{http_code}" "http://${DOMAIN}/health" | grep -q 200; then
  ok "Health: http://${DOMAIN}/health → 200"
else
  echo "  ⚠️  DNS 可能尚未生效，等待传播后访问: http://${SERVER}:8080/health"
fi

rm -f "$PACKAGE" "$NGINX_CONF"

echo ""
echo "══════════════════════════════════════════"
echo "  ✅ ${DOMAIN} 部署完成"
echo "══════════════════════════════════════════"
echo ""
echo "  访问: http://${DOMAIN}"
echo "  API:  http://${DOMAIN}/health"
echo "  SSH:  ssh ${SSH_USER}@${SERVER}"
echo ""
echo "  查看日志: ssh ${SSH_USER}@${SERVER} 'docker logs ${PROJECT}'"
echo "  重启服务: ssh ${SSH_USER}@${SERVER} 'docker restart ${PROJECT}'"
echo ""
echo "  下一步:"
echo "    1. 配置 SSL: certbot --nginx -d ${DOMAIN}"
echo "    2. 设置每日 Cron: curl -X POST https://${DOMAIN}/api/payment-router/cron/daily"
