# PaymentRouter — 客户交付方案

> 核心原则: 让不同技术能力的客户，用最简单的方式接入。Cloak 做入口，支付路由做增值。

---

## 一、TrafficArmor 模式 vs 我们的改进

```
TrafficArmor: 注册 → 贴JS → 完成 (只做 Cloak, $129/月)
我们:         注册 → 贴JS → Cloak 生效 + 可选支付路由 ($149/月)
                     ↑                ↑
                 零安装 1 分钟       WP 一键装插件 5 分钟
```

**我们的优势**: 同样的零安装 Cloak + 多了支付路由。客户不用买两个工具。

---

## 二、按客户类型交付

### 🌱 入门版 ($149/月 SaaS) — 最快 1 分钟接入

```
步骤:
  1. customer.paymentrouter.dev 注册 → 自动 14 天试用
  2. 后台获取 JS 代码:
     <script src="https://customer.paymentrouter.dev/embed.js?key=ck_xxx&safe=B站URL&real=A站URL"></script>
  3. 贴到 A 站 <head> → Cloak 生效 ✅

  (可选) 支付路由:
  4. WP 后台 → 插件 → 搜索 "AB Payment Router" → 一键安装
  5. 填入中控地址 + API Key → 保存
  6. OC 后台 → 扩展 → 上传 ab_router.ocmod.zip → 启用

  交付物: JS 代码段 + WP 插件 + OC 扩展
  技术门槛: 零 (会复制粘贴就行)
```

### 💼 专业版 ($800 买断) — 15 分钟自部署

```
步骤:
  1. 购买 → 收到 License Key + 下载链接
  2. 下载 payment-router-v0.1.0.zip
  3. 上传到 VPS → bash install.sh
  4. 激活: curl -X POST /api/license/activate -d '{"key":"PR-XXXX"}'
  5. 后续同上 (装 WP 插件 + 配置)

  交付物: 源码 zip + License Key + 安装手册
  技术门槛: 低 (需会 SSH 登录 VPS)
  增值服务: +$200 代部署 (我们远程帮你装好)
```

### 🏢 企业版 ($2,500+) — 白手套交付

```
步骤:
  1. 需求沟通 → 域名/品牌/支付通道确认
  2. 我们部署到客户服务器 (或我们托管)
  3. OEM 白标: Logo/品牌名/配色替换
  4. 培训: 1 小时视频会议教客户使用
  5. SLA: 4 小时内响应, 99.9% 可用

  交付物: 部署好的系统 + OEM 品牌 + 培训 + SLA
  技术门槛: 零 (我们全包)
```

---

## 三、交付物标准化

| 版本 | 交付物 | 格式 | 获取方式 |
|------|------|------|------|
| 入门版 | embed.js | 1 行 `<script>` | 后台复制 |
| 入门版 | WP 插件 | .zip | WordPress.org 插件市场 |
| 入门版 | OC 扩展 | .ocmod.zip | 后台下载 |
| 专业版 | 完整源码 | .zip | License 激活后下载 |
| 专业版 | 安装脚本 | install.sh | 源码包内含 |
| 专业版 | 部署文档 | PDF/网页 | 源码包内含 |
| 企业版 | OEM 定制包 | .zip | 专属交付 |

---

## 四、推荐的销售漏斗

```
免费 Cloak          入门版             专业版              企业版
(社区版限制)  →   ($149/月)     →    ($800买断)    →   ($2,500+)
────────────────────────────────────────────────────────────
1A+1B            1A+2B             2A+5B             不限
仅 weighted      4 策略            自定义              DSL 脚本
无仪表盘         完整仪表盘         源码自主            OEM 白标
社区支持         邮件支持           年费更新 $150      专属 SLA

获客:           转化:              利润:              品牌:
GitHub 开源      14天免费试用        一次性高毛利        高价值锁定
SEO 内容         JS 贴码 1 分钟     代部署 $200 增值    定制化服务
```

---

## 五、对比 TrafficArmor

| | TrafficArmor | PaymentRouter |
|------|:---:|:---:|
| Cloak 接入 | 1 行 JS | 1 行 JS ✅ 同等 |
| 支付路由 | ❌ 无 | ✅ 有 (差异化) |
| 价格 | $129/月起 | $149/月起 (多 $20 多支付路由) |
| 开源 | ❌ | ✅ 社区版免费 |
| 自部署 | ❌ | ✅ 专业版源码 |
| WP 插件 | ❌ | ✅ WooCommerce 一键安装 |
| 中文 | ❌ | ✅ 中英双语 |

---

## 六、交付动作清单

上线前准备:

```
□ 购买域名: paymentrouter.dev (或类似)
□ 部署 SaaS 实例: 1 台 VPS ($20/月 Hetzner)
□ 配置 SSL (Let's Encrypt)
□ 配置 SMTP (Resend 免费层 100封/天)
□ WP 插件提交到 WordPress.org 插件目录
□ GitHub 仓库设为 Public + README 完善
□ 制作 2 分钟演示视频 (Loom 免费)
□ 定价页面上线 + Stripe Payment Link
```

上线后运营:

```
□ 小红书/知乎发布 "独立站防封指南" 引流
□ Hostloc/广告中国发技术干货帖
□ Telegram 群组沉淀种子用户
□ 每周更新 IP 规则库 + UA 特征
```
