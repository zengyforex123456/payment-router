# PaymentRouter SaaS — 完整度评估 v1.0

## 当前状态

| 模块 | 状态 | 说明 |
|------|:---:|------|
| API 引擎 | ✅ 完成 | 28 端点，HMAC+JWT，4 策略，冷却/恢复 |
| 管理面板 | ⚠️ 仅 API | admin.html 是基于 API 的 SPA，无后端渲染 |
| Docker 部署 | ✅ 完成 | 一键部署 |
| WP/OC 插件 | ✅ 完成 | A站+B站连接器 |
| 商业化 | ✅ 完成 | License/Trial/Upgrade/Billing |
| i18n | ✅ 完成 | 中英双语 120+ 词条 |

## 缺失评估

| # | 缺失项 | 优先级 | 影响 |
|:---:|------|:---:|------|
| 1 | **着陆页** (Marketing Landing) | 🔴 P0 | 无面向公众的产品展示页面。潜在客户不知道这是什么、怎么用、多少钱 |
| 2 | **注册/登录** (Auth) | 🔴 P0 | 无用户系统。所有 API 假设 tenant_id=0（单机模式），SaaS 需要多用户隔离 |
| 3 | **客户门户** (Customer Portal) | 🟡 P1 | 登录后需要有：我的站点、用量仪表盘、License 状态、升级入口、付款历史 |
| 4 | **定价页** (Pricing Page) | 🔴 P0 | 包含在着陆页中。展示三级定价 + 功能对比表 |
| 5 | **文档站** (Documentation) | 🟡 P1 | 已有 docs/*.md，但未展示为网页 |
| 6 | **用户设置** (Settings/Profile) | 🟢 P2 | 修改密码、通知偏好、API Key 管理 |
| 7 | **Email 通知** (Transactional Email) | 🟢 P2 | 注册确认、密码重置、付款收据、试用到期提醒 |

## 需要新建的文件

```
public/
├── index.html          ← 着陆页 (Hero + Features + Pricing + CTA)
├── login.html          ← 登录页
├── register.html       ← 注册页
├── app.html             ← 客户门户 (整合 admin 功能 + 用户上下文)
├── pricing.html        ← 定价页
└── css/
    └── landing.css     ← 着陆页样式

modules/PaymentRouter/
├── Application/
│   └── AuthUseCase.php       ← 注册/登录/密码重置
├── Domain/
│   └── User.php              ← 用户实体
├── Infrastructure/
│   └── MysqlUserRepository.php ← 用户仓储
└── database/migrations/
    └── 090_create_users_table.sql

docker/payment-router/index.php  ← 更新路由: 页面 + 登录/注册端点
```

## 实施计划

本次实施 P0（着陆页 + 注册登录 + 客户门户），约 8 个文件。
