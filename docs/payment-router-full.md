# PaymentRouter — AB 轮询支付中控

将支付请求从 WordPress/WooCommerce "A 站"（展示站）智能分发到多个 OpenCart "B 站"（收款站），规避单一 PayPal/Stripe 账户被冻结导致的全盘停摆。

---

## 架构

```
A 站 (WooCommerce) ──HMAC──→  中控 (PaymentRouter)  ──JWT──→  B 站 (OpenCart)
  ab-payment-router WP 插件      SelectGateway                  ab_router OC 扩展
                                  DispatchOrder                  创建订单 → PayPal/Stripe
                                  Webhook ←────────────────────── 支付结果回调
                                  Dashboard
```

## 快速开始

### Docker（推荐）

```bash
cp .env.payment-router .env
docker compose -f docker-compose.payment-router.yml up -d
curl http://localhost:8080/health
# → {"status":"ok","service":"payment-router"}
```

### 手动安装

```bash
bash scripts/install.sh
```

### 三步验证

```bash
# 1. 注册 A 站（获取 API Key）
curl -X POST http://localhost:8080/api/payment-router/a-sites \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"domain":"shop.example.com"}'

# 2. 注册 B 站
curl -X POST http://localhost:8080/api/payment-router/b-sites \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"domain":"pay.example.com","payment_gateway":"paypal","weight":5}'

# 3. 分发订单
# (需要 HMAC 签名 — 见 API 文档)
```

## 版本

| 版本 | 价格 | 用户 | 功能 |
|:---:|------|------|------|
| **入门版** | $86/月 | 个人卖家 | 1A+2B, 预设策略, SaaS 托管 |
| **专业版** | $600-700 | 中型卖家 | 2A+5B, 源码买断, 自定义策略, 本地部署 |
| **企业版** | $2000+ | 站群/代理 | 不限A/B站, DSL路由脚本, OEM白标, 多租户 |

## 文档

| 文档 | 内容 |
|------|------|
| [部署指南](docs/DEPLOY.md) | Docker、手动安装、systemd、HTTPS、备份 |
| [API 参考](docs/API.md) | 全部 20 个端点、认证、错误码 |
| [用户手册](docs/USER_GUIDE.md) | 从零搭建 AB 站、策略选择、故障排查 |
| [WP 插件](modules/PaymentRouter/wordpress-plugin/readme.txt) | WooCommerce A 站连接器 |
| [OC 插件](modules/PaymentRouter/opencart-plugin/) | OpenCart B 站收款扩展 |

## 要求

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Composer（可选）
- Docker（可选）
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
# PaymentRouter — API 参考

## 基础信息

- **Base URL**: `https://your-controller.example.com`
- **Content-Type**: `application/json`
- **认证方式**: 
  - 外部 API: API Key + HMAC-SHA256 签名
  - 管理 API: Session（浏览器）/ Bearer Token（API）

## 认证

### 外部 API 认证（A 站 → 中控）

每个请求必须包含 HMAC 签名：

```bash
# 签名算法
TIMESTAMP=$(date +%s)
PAYLOAD='{"a_order_id":"ORDER-001","amount":"99.99","currency":"USD","timestamp":"'"$TIMESTAMP"'"}'
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$API_KEY" | awk '{print $2}')

curl -X POST https://controller.example.com/api/payment-router/dispatch \
  -H "Content-Type: application/json" \
  -H "X-Signature: $SIGNATURE" \
  -H "X-Api-Key: $API_KEY" \
  -d "{\"api_key\":\"$API_KEY\",\"signature\":\"$SIGNATURE\",\"a_order_id\":\"ORDER-001\",\"amount\":\"99.99\",\"currency\":\"USD\",\"timestamp\":\"$TIMESTAMP\"}"
```

### 错误响应

所有错误返回统一格式：

```json
{"error": "错误描述"}
```

| HTTP | 含义 |
|:---:|------|
| 200 | 成功 |
| 400 | 请求参数错误 |
| 401 | 认证失败（API Key 无效或签名错误） |
| 404 | 资源不存在 |
| 503 | 服务不可用（数据库断开、所有 B 站不可用） |

---

## 外部 API

### POST /api/payment-router/dispatch

A 站提交订单，中控选择一个 B 站并返回跳转 URL。

**请求体**:

| 字段 | 类型 | 必填 | 说明 |
|------|------|:---:|------|
| `api_key` | string | ✅ | A 站 API Key |
| `signature` | string | ✅ | HMAC-SHA256 签名 |
| `a_order_id` | string | ✅ | A 站订单号（唯一） |
| `amount` | string | ✅ | 订单金额 |
| `currency` | string | | 货币（默认 USD） |
| `timestamp` | string | ✅ | Unix 时间戳 |
| `strategy` | string | | 策略覆盖（可选） |

**响应**:

```json
{
  "b_checkout_url": "https://pay1.example.com/index.php?route=extension/payment/ab_router/checkout&token=eyJ...",
  "b_order_reference": "B-A1B2C3D4E5F6",
  "b_site_domain": "pay1.example.com"
}
```

### POST /api/payment-router/webhook

B 站支付结果回调。由 OC 插件自动调用，无需手动触发。

**请求体**:

| 字段 | 类型 | 必填 | 说明 |
|------|------|:---:|------|
| `b_order_id` | string | ✅ | B 站订单引用 |
| `status` | string | ✅ | `paid` / `failed` / `refunded` |
| `transaction_id` | string | | 支付网关交易 ID |

**响应**:

```json
{"acknowledged": true, "mapping_status": "paid", "b_site_status": "recovered"}
```

---

## 管理 API

### A 站管理

```
GET    /api/payment-router/a-sites          列出所有 A 站
POST   /api/payment-router/a-sites          注册 A 站
DELETE /api/payment-router/a-sites/{id}     删除 A 站
```

**POST 请求体**: `{"tenant_id":0,"domain":"shop.example.com","platform":"woocommerce"}`

**POST 响应**: `{"id":1,"domain":"shop.example.com","apiKey":"ck_...","status":"active"}`

### B 站管理

```
GET    /api/payment-router/b-sites          列出所有 B 站
POST   /api/payment-router/b-sites          注册 B 站
```

**POST 请求体**: `{"tenant_id":0,"domain":"pay.example.com","payment_gateway":"paypal","weight":5,"max_daily_orders":100}`

**POST 响应**: `{"id":1,"domain":"pay.example.com","gateway":"paypal","status":"active"}`

### 仪表盘 & 查询

```
GET /api/payment-router/dashboard    仪表盘汇总 + B 站明细
GET /api/payment-router/mappings     订单映射列表 (A→B)
GET /api/payment-router/usage        租户用量 + 套餐限制
```

**Dashboard 响应**:

```json
{
  "summary": {
    "total_orders": 150,
    "paid_orders": 142,
    "failed_orders": 5,
    "pending_orders": 3,
    "total_revenue": 12500.50,
    "success_rate": 94.7
  },
  "b_sites": [
    {"domain":"pay1.example.com","total_mapped":80,"success_count":78,"fail_count":2}
  ]
}
```

### 策略配置

```
GET    /api/payment-router/strategy        获取当前策略
POST   /api/payment-router/strategy        应用预设模板
PATCH  /api/payment-router/strategy        自定义策略参数
GET    /api/payment-router/presets         列出可用预设
```

**POST 请求体**: `{"tenant_id":0,"preset":"safe_mode"}`

**PATCH 请求体**: `{"tenant_id":0,"cooling_threshold":2,"cooldown_minutes":15}`

**预设列表**:

| 预设 | 路由 | 冷却阈值 | 冷却时间 |
|------|:---:|:---:|:---:|
| `balanced` | weighted | 3 | 30 min |
| `weight_priority` | weighted | 5 | 60 min |
| `safe_mode` | round_robin | 1 | 15 min |
| `high_volume` | random | 10 | 120 min |

### 配置管理（专业版+）

```
GET  /api/payment-router/config/export    导出全量配置 JSON
POST /api/payment-router/config/import    导入配置 JSON
```

### 批量导入（企业版）

```
POST /api/payment-router/bulk/import/a-sites    批量导入 A 站
POST /api/payment-router/bulk/import/b-sites    批量导入 B 站
```

**请求体**: `{"tenant_id":0,"sites":[{"domain":"shop1.com"},{"domain":"shop2.com"}]}`

**响应**: `{"imported":2,"skipped":0,"errors":[]}`

### 路由脚本（企业版）

```
POST /api/payment-router/routing-script/validate   验证 DSL 规则
POST /api/payment-router/routing-script/evaluate   执行路由脚本
```

**DSL 语法**:

```json
{
  "rules": [
    {"condition": "amount_gt:100",    "action": "prefer:weight_gte:5"},
    {"condition": "gateway:stripe",   "action": "round_robin"},
    {"condition": "currency:EUR",     "action": "random"},
    {"condition": "default",          "action": "weighted"}
  ],
  "context": {"amount": "150.00", "gateway": "paypal", "currency": "USD"}
}
```

**支持的条件**: `amount_gt:N` / `amount_lte:N` / `gateway:X` / `currency:X` / `default`

**支持的动作**: `prefer:weight_gte:N` / `round_robin` / `random` / `weighted`

### 企业功能

```
GET /api/payment-router/oem                 获取 OEM 品牌配置
GET /api/payment-router/admin/tenants       多租户管理概览
POST /api/payment-router/health-check       手动触发健康检查
```

### 健康检查

```
GET /health     {"status":"ok","service":"payment-router","time":"..."}
```

---

## 客户端集成示例

### PHP (WordPress)

```php
$apiKey = get_option('abpr_api_key');
$ts = (string)time();
$payload = json_encode(['a_order_id'=>'42','amount'=>'99.99','currency'=>'USD','timestamp'=>$ts]);
$sig = hash_hmac('sha256', $payload, $apiKey);

$resp = wp_remote_post('https://controller.example.com/api/payment-router/dispatch', [
    'body' => json_encode([
        'api_key'   => $apiKey,
        'signature' => $sig,
        'a_order_id'=> '42',
        'amount'    => '99.99',
        'currency'  => 'USD',
        'timestamp' => $ts,
    ]),
    'headers' => ['Content-Type' => 'application/json'],
]);
$result = json_decode(wp_remote_retrieve_body($resp), true);
// 重定向用户到 $result['b_checkout_url']
```

### Python

```python
import hmac, hashlib, json, time, requests

api_key = "ck_..."
ts = str(int(time.time()))
payload = json.dumps({"a_order_id":"42","amount":"99.99","currency":"USD","timestamp":ts})
sig = hmac.new(api_key.encode(), payload.encode(), hashlib.sha256).hexdigest()

resp = requests.post("https://controller.example.com/api/payment-router/dispatch", json={
    "api_key": api_key, "signature": sig,
    "a_order_id": "42", "amount": "99.99", "currency": "USD", "timestamp": ts,
})
print(resp.json()["b_checkout_url"])
```

### cURL

```bash
TS=$(date +%s)
PAYLOAD='{"a_order_id":"42","amount":"99.99","currency":"USD","timestamp":"'$TS'"}'
SIG=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$API_KEY" | awk '{print $2}')

curl -X POST https://controller.example.com/api/payment-router/dispatch \
  -H "Content-Type: application/json" \
  -d '{"api_key":"'$API_KEY'","signature":"'$SIG'","a_order_id":"42","amount":"99.99","currency":"USD","timestamp":"'$TS'"}'
```
# PaymentRouter — 用户手册

从零搭建 AB 站轮询支付系统的完整操作指南。

---

## 目录

1. [概念理解](#概念理解)
2. [部署中控](#部署中控)
3. [配置 A 站（WordPress）](#配置-a-站)
4. [配置 B 站（OpenCart）](#配置-b-站)
5. [第一次支付测试](#第一次支付测试)
6. [策略选择指南](#策略选择指南)
7. [日常运维](#日常运维)
8. [常见问题](#常见问题)

---

## 概念理解

### 什么是 AB 站？

```
A 站（展示站）：顾客看到的独立站，展示商品，承接广告流量。不直接处理支付。
B 站（收款站）：放置合规普货的独立站，绑定 PayPal/Stripe 账号。实际完成收款。
中控：连接 A/B 站的智能调度系统。
```

### 为什么需要？

单一收款账号被 PayPal/Stripe 冻结 = 全盘停摆 + 180 天资金冻结。
多个 B 站轮询 → 每个账号收款量分散 → 降低风控触发概率。

### 支付流程

```
顾客在 A 站下单
  → WP 插件将订单发到中控
  → 中控根据策略选一个 B 站
  → 顾客无感知跳转到 B 站支付页面
  → PayPal/Stripe 处理支付
  → 结果回传 A 站 + 中控
```

---

## 部署中控

### 方式一：Docker（推荐新手）

```bash
# 1. 下载项目
git clone https://github.com/example/payment-router.git
cd payment-router

# 2. 配置
cp .env.payment-router .env
nano .env  # 修改 APP_SECRET 和 DB_PASSWORD

# 3. 启动
docker compose -f docker-compose.payment-router.yml up -d

# 4. 验证
curl http://localhost:8080/health
```

### 方式二：VPS 手动部署

```bash
bash scripts/install.sh
```

安装脚本自动：检测环境 → 创建数据库 → 迁移表 → 注册 systemd 服务 → 验证。

完成后访问 `http://YOUR_VPS_IP:8080/health` 确认。

---

## 配置 A 站

### 1. 在 WordPress 后台安装插件

上传 `ab-payment-router.zip` 到 `/wp-content/plugins/`，激活。

### 2. 配置连接

进入 **WooCommerce → 设置 → AB 轮询支付**：

| 字段 | 值 |
|------|------|
| 中控地址 | `https://your-controller.com` |
| API Key | 点击"注册 A 站"自动获取 |

### 3. 注册 A 站到中控

在 WP 后台点击 **测试连接**。成功后中控会生成 API Key，保存。

或者通过 API：

```bash
curl -X POST https://controller.com/api/payment-router/a-sites \
  -H "Content-Type: application/json" \
  -d '{"domain":"your-shop.com","platform":"woocommerce"}'
# 记录返回的 apiKey
```

### 4. 配置 Webhook

WP 插件自动注册 REST API 端点：`https://your-shop.com/wp-json/abpr/v1/webhook`

在 WP 后台 **AB 轮询支付设置** 页面可看到此 URL。将此 URL 保存——中控支付成功后回调此地址更新订单状态。

---

## 配置 B 站

### 1. 在 OpenCart 后台安装扩展

将 `upload/` 目录内容复制到 OpenCart 根目录。

进入 **Extensions → Payments → AB Payment Router → Install**。

### 2. 配置

| 字段 | 值 |
|------|------|
| Controller URL | `https://your-controller.com` |
| Shared Secret | 与中控 `APP_SECRET` 相同 |
| Payment Gateway | 选择 PayPal 或 Stripe |
| Fallback Product ID | 选择一个普货商品 ID |
| Status | Enabled |

### 3. 注册 B 站到中控

```bash
curl -X POST https://controller.com/api/payment-router/b-sites \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"domain":"pay1.example.com","payment_gateway":"paypal","weight":5,"max_daily_orders":100}'
```

### 4. 配置支付网关

确保 OpenCart 后台 PayPal/Stripe 已正确配置并启用。
AB Router 会自动将支付请求转发到您选择的网关。

### 5. 建议：B 站数量

| 月订单量 | 建议 B 站数 |
|:---:|:---:|
| < 500/月 | 2-3 个 |
| 500-2000/月 | 3-5 个 |
| 2000-10000/月 | 5-10 个 |
| > 10000/月 | 10-20 个 |

---

## 第一次支付测试

### 1. 确认连接

```bash
# 检查 A 站已注册
curl https://controller.com/api/payment-router/a-sites

# 检查 B 站已注册且可用
curl https://controller.com/api/payment-router/b-sites
# 确认 status = "active"
```

### 2. 模拟下单

在 A 站（WordPress）前台正常下单。观察：

1. 下单后是否跳转到 B 站支付页面
2. B 站 URL 是否正常（HTTPS、正确的商品信息）
3. 支付完成是否回到 A 站成功页面

### 3. 检查中控日志

```bash
curl https://controller.com/api/payment-router/mappings
# 应看到订单映射记录: A-Order → B-Order → paid/failed
```

### 4. 检查 WP 订单状态

在 WordPress 后台 → WooCommerce → 订单：
- 支付成功 → 状态应为 "Processing" 或 "Completed"
- 支付失败 → 状态应为 "Failed"

---

## 策略选择指南

### 预设模板

| 模板 | 适用场景 | 特点 |
|------|------|------|
| **balanced** | 通用 | 权重随机，3 次失败冷却 30 分钟 |
| **weight_priority** | 有优质账号 | 高权重 B 站优先，5 次失败才冷却 |
| **safe_mode** | 新账号养号期 | 轮询均匀分配，1 次失败立即冷却 |
| **high_volume** | 大流量站群 | 随机分配，10 次失败冷却 |

### 应用模板

```bash
curl -X POST https://controller.com/api/payment-router/strategy \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"preset":"safe_mode"}'
```

### 自定义参数（专业版+）

```bash
curl -X PATCH https://controller.com/api/payment-router/strategy \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"cooling_threshold":2,"cooldown_minutes":20,"routing_method":"weighted"}'
```

### B 站权重建议

```
权重 5: 优质 PayPal 老号（养了 >6 个月，低退款率）
权重 3: 正常 PayPal 号
权重 1: Stripe 新号 / 备选号
```

---

## 日常运维

### 每日检查

```bash
# 仪表盘
curl https://controller.com/api/payment-router/dashboard

# 关注指标:
#   success_rate: 应 >90%
#   B 站 status: 不应有过多 cooling
```

### B 站健康维护

- 每周给每个 B 站手动下一笔小额真实订单（$1-5），保持账号活跃
- B 站商品目录定期更新（不要看起来像"空壳站"）
- 各 B 站使用不同的 PayPal/Stripe 账号，不同的银行账户

### 添加新 B 站

```bash
curl -X POST https://controller.com/api/payment-router/b-sites \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"domain":"pay-new.example.com","payment_gateway":"paypal","weight":3}'
```

### 暂停 B 站

当 B 站需要维护或更换账号时，在 OpenCart 后台禁用 AB Router 插件即可。
中控会自动排除 disabled 状态的 B 站。

### 备份

```bash
# 导出配置（专业版+）
curl https://controller.com/api/payment-router/config/export?tenant_id=0 > backup-config.json
```

---

## 常见问题

### Q: 顾客看到 B 站域名和 A 站不同，会不会疑惑？

顾客在支付环节的注意力在付款界面（PayPal/Stripe），通常不会关注浏览器地址栏变化。B 站应配置与 A 站相似的视觉风格以降低违和感。

### Q: B 站被 PayPal 封了怎么办？

1. 中控自动检测 → B 站标记为 cooling → 流量分配到其他 B 站
2. 重新注册新的 PayPal 账号 + 新的 B 站域名
3. 新 B 站先用 `safe_mode` 策略养号 2-4 周

### Q: 如何知道哪个 B 站收款最多？

```bash
curl https://controller.com/api/payment-router/dashboard
# 查看 b_sites 数组中各站的 total_mapped 和 success_count
```

### Q: 订单金额在 A 站和 B 站不一致？

WP 插件将 A 站订单金额原样传给中控，中控传给 B 站。B 站 OC 插件以此金额创建订单。全程金额不变。

### Q: 支持 Stripe 吗？

支持。B 站 OpenCart 安装 Stripe 扩展后，在 AB Router 设置中选择 `stripe` 作为网关即可。

### Q: 可以同时用 PayPal 和 Stripe 吗？

可以。注册多个 B 站，分别配置不同的 `payment_gateway`。中控会在所有可用 B 站中按策略选择。

### Q: JWT 过期 ("Invalid or expired payment token")

JWT 有效期 15 分钟。用户从 A 站跳转到 B 站后需在 15 分钟内完成支付。超时需重新下单。

### Q: 升级版本会丢失数据吗？

不会。数据库迁移是增量式的（只加表/字段，不删数据）。升级前建议先备份。

---

## 支持

- API 文档: [docs/API.md](API.md)
- 部署指南: [docs/DEPLOY.md](DEPLOY.md)
- 问题反馈: GitHub Issues
