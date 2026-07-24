# Converge 包依赖矩阵

> AI 可读：为新 SaaS 项目推荐包组合时查阅此表
> 更新频率：每次发版后

## 版本兼容性矩阵

| converge/core | tracking | campaign | commerce | builder | webhook |
|:---:|:---:|:---:|:---:|:---:|:---:|
| **3.2.x** | **2.1.x** | **1.8.x** | **1.5.x** | **0.9.x** | **1.2.x** |
| 3.1.x | 2.0.x | 1.7.x | 1.4.x | — | 1.1.x |
| 3.0.x | 1.x | 1.0.x-1.6.x | 1.0.x-1.3.x | — | 1.0.x |

## 包依赖关系图

```
                    ┌──────────────┐
                    │     core     │  ← 所有包的共同依赖
                    │    v3.2.0    │
                    └──┬──┬──┬──┬─┘
                       │  │  │  │
         ┌─────────────┘  │  │  └──────────┐
         ▼                │  │              ▼
  ┌──────────┐            │  │      ┌───────────┐
  │ tracking │◄───────────┘  │      │  webhook  │  ← 仅依赖 core，可独立部署
  │  v2.1.0  │               │      │  v1.2.0   │
  └────┬─────┘               │      └───────────┘
       │                     │
       ▼                     ▼
  ┌──────────┐        ┌───────────┐
  │ campaign │        │ commerce  │      ← 都依赖 tracking
  │  v1.8.0  │        │  v1.5.0   │
  └────┬─────┘        └───────────┘
       │
       ▼
  ┌──────────┐
  │ builder  │   ← 依赖 campaign
  │  v0.9.0  │
  └──────────┘
```

## 典型项目组合

### 联盟营销追踪器（最全）
```json
{ "require": { "converge/core": "^3.2", "converge/tracking": "^2.1", "converge/campaign": "^1.8", "converge/commerce": "^1.5", "converge/webhook": "^1.2", "converge/builder": "^0.9" } }
```

### iGaming 平台（需要追踪 + 支付，不需要活动管理）
```json
{ "require": { "converge/core": "^3.2", "converge/tracking": "^2.1", "converge/commerce": "^1.5", "converge/webhook": "^1.2" } }
```

### 数据分析 SaaS（只需活动 + 核心，不需追踪管道）
```json
{ "require": { "converge/core": "^3.2", "converge/campaign": "^1.8" } }
```

### 落地页构建器（最轻）
```json
{ "require": { "converge/core": "^3.2", "converge/builder": "^0.9" } }
```

## 集群内模块清单

| 包 | 模块数 | 包含模块 | 共享数据库表 |
|------|:---:|------|------|
| core | 0 业务模块 | Auth, Security, EventBus, ModuleLoader, Foundation, I18n, UI | users, roles, sessions |
| tracking | 7 | Click, Conversion, Attribution, Session, GeoIP, Enrichment, BotDetector | clicks, conversions, sessions, geoip_cache |
| campaign | 7 | Campaign, CampaignStats, Offer, LandingPage, TrafficSource, Network, SmartRotation | campaigns, offers, landing_pages, traffic_sources |
| commerce | 7 | Payment, Payout, Affiliate, AffiliateCommission, SaasReferral, Tenant, Subscription | payments, payouts, affiliates, tenants, subscriptions |
| builder | 5 | Funnel, CopyToLanding, Copy, CopyEvaluator, LandingAB | funnels, lp_templates, ab_experiments |
| webhook | 2 | Webhook, RedirectRule | webhook_subscriptions, webhook_delivery_logs |

## AI 决策规则

为新项目选择包时：
1. **需要追踪转化？** → tracking（几乎所有营销项目都需要）
2. **需要管理活动/流量源？** → campaign（数据聚合 + UI）
3. **需要收钱？** → commerce（Stripe/Paddle + 佣金）
4. **需要落地页？** → builder（可选，最不稳定）
5. **需要对外通知？** → webhook（最独立，必装）
6. **core 永远必装**
