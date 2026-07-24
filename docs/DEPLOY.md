# PaymentRouter — 部署指南

## 目录
1. [Docker 部署](#docker-部署)
2. [手动安装](#手动安装)
3. [systemd 服务](#systemd-服务)
4. [HTTPS 配置](#https-配置)
5. [数据库备份](#数据库备份)
6. [健康监控](#健康监控)
7. [升级指南](#升级指南)
8. [故障排查](#故障排查)

---

## Docker 部署

### 前置条件

- Docker 20.10+
- Docker Compose 2.0+

### 步骤

```bash
# 1. 复制环境配置
cp .env.payment-router .env
# 编辑 .env: 修改 APP_SECRET、DB_PASSWORD

# 2. 启动
docker compose -f docker-compose.payment-router.yml up -d

# 3. 验证
curl http://localhost:8080/health
# → {"status":"ok","service":"payment-router","time":"..."}

# 4. 查看日志
docker compose -f docker-compose.payment-router.yml logs -f api
```

### .env 关键配置

| 变量 | 默认 | 说明 |
|------|------|------|
| `APP_SECRET` | `change-me` | **必须修改**。用于 JWT 签名和 Webhook 验证 |
| `DB_PASSWORD` | `payment_router_secret` | **必须修改**。MySQL 密码 |
| `APP_PORT` | `8080` | API 服务端口 |
| `DB_NAME` | `payment_router` | 数据库名 |

### 停止

```bash
docker compose -f docker-compose.payment-router.yml down       # 保留数据
docker compose -f docker-compose.payment-router.yml down -v    # 删除数据
```

---

## 手动安装

### 前置条件

- Ubuntu 20.04+ / Debian 11+ / CentOS 8+
- PHP 8.0+ (cli, mysqli, mbstring, openssl, curl)
- MySQL 5.7+ 或 MariaDB 10.3+

### 一键安装

```bash
curl -sSL https://your-cdn.com/install.sh | bash
```

### 分步安装

```bash
# 1. 安装 PHP 扩展
apt install php-cli php-mysqli php-mbstring php-curl php-xml

# 2. 创建数据库
mysql -u root -p -e "
  CREATE DATABASE IF NOT EXISTS payment_router;
  CREATE USER IF NOT EXISTS 'payment_router'@'localhost' IDENTIFIED BY 'your_password';
  GRANT ALL ON payment_router.* TO 'payment_router'@'localhost';
  FLUSH PRIVILEGES;
"

# 3. 执行迁移
mysql -u payment_router -p payment_router < database/migrations/084_create_payment_router_tables.sql
mysql -u payment_router -p payment_router < database/migrations/085_create_payment_router_saas_tables.sql

# 4. 配置环境
cat > .env <<EOF
APP_ENV=production
APP_SECRET=$(openssl rand -hex 32)
APP_PORT=8080
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=payment_router
DB_USER=payment_router
DB_PASSWORD=your_password
EOF

# 5. 启动
php -S 0.0.0.0:8080 -t . docker/payment-router/index.php
```

---

## systemd 服务

### 注册服务

`install.sh` 自动执行此步骤。手动创建：

```ini
# /etc/systemd/system/payment-router.service
[Unit]
Description=PaymentRouter API
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/payment-router
EnvironmentFile=/opt/payment-router/.env
ExecStart=/usr/bin/php -S 0.0.0.0:8080 -t /opt/payment-router /opt/payment-router/docker/payment-router/index.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now payment-router
systemctl status payment-router
```

### 日志

```bash
journalctl -u payment-router -f       # 实时日志
journalctl -u payment-router -n 50    # 最近 50 行
```

---

## HTTPS 配置

### Nginx 反向代理（推荐）

```nginx
server {
    listen 443 ssl http2;
    server_name payment.example.com;

    ssl_certificate     /etc/ssl/certs/payment.pem;
    ssl_certificate_key /etc/ssl/private/payment.key;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Let's Encrypt 证书

```bash
certbot --nginx -d payment.example.com
```

---

## 数据库备份

### 自动备份脚本

```bash
#!/bin/bash
# /etc/cron.daily/backup-payment-router
BACKUP_DIR="/opt/backups/payment-router"
mkdir -p "$BACKUP_DIR"
mysqldump -u payment_router -p'your_password' payment_router \
  | gzip > "$BACKUP_DIR/payment_router_$(date +%Y%m%d).sql.gz"
# 保留最近 30 天
find "$BACKUP_DIR" -mtime +30 -delete
```

### 恢复

```bash
gunzip < payment_router_20260724.sql.gz | mysql -u payment_router -p payment_router
```

---

## 健康监控

### 端点

```bash
GET /health
# → {"status":"ok","service":"payment-router","time":"2026-07-24T00:00:00Z"}
```

### Prometheus / UptimeRobot

将 `/health` 端点配置到您的监控工具中，每 60 秒检查一次。返回非 200 即触发告警。

### 内置健康检查

```bash
# 手动触发 B 站健康探测
curl -X POST http://localhost:8080/api/payment-router/health-check
# → {"checked": 3, "cooled": 0, "recovered": 1}
```

---

## 升级指南

### v0.0.x → v0.1.0

```bash
# 1. 备份数据库
mysqldump -u payment_router -p payment_router > backup_before_upgrade.sql

# 2. 拉取新代码
cd /opt/payment-router
git pull origin main

# 3. 执行新迁移
mysql -u payment_router -p payment_router < database/migrations/085_create_payment_router_saas_tables.sql

# 4. 重启服务
systemctl restart payment-router

# 5. 验证
curl http://localhost:8080/health
```

---

## 故障排查

| 症状 | 检查 | 解决 |
|------|------|------|
| `503 DB connection` | MySQL 是否运行 | `systemctl status mysql` |
| `401 签名验证失败` | API Key 是否正确 | 检查 A 站配置的 `api_key` |
| `503 所有 B 站不可用` | B 站是否全部冷却/达上限 | `GET /api/payment-router/b-sites` 检查状态 |
| `404 Not Found` | 路由是否匹配 | 检查请求路径和 Method |
| 订单未回调 | Webhook URL 是否正确 | 在 A 站 WP 后台检查 Webhook 端点 |
| B 站 JWT 过期 | 15 分钟时效 | 用户需在 15 分钟内完成支付 |
