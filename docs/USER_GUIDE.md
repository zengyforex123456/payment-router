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
