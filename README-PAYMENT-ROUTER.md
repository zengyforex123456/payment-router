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
| **入门版** | $149/月 | 个人卖家 | 1A+2B, 预设策略, SaaS 托管, 含服务器 |
| **专业版** | $600-700 一次性 | 中型卖家 | 2A+5B, 源码买断, 自定义策略, 本地部署 |
| **企业版** | $2000+ 一次性 | 站群/代理 | 不限A/B站, DSL路由脚本, OEM白标, 多租户, SLA |

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
