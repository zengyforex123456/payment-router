---
name: converge-capability-index
description: Converge 70+可复用模块能力索引 — CAPABILITIES.md·12类别·调用签名·门控开关速查
metadata: 
  node_type: memory
  type: reference
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# Converge 能力索引 (CAPABILITIES.md)

**文件**: `projects/converge/data/source/CAPABILITIES.md`
**更新**: 2026-07-13
**策略**: LLM 先查这里·不翻源码

## 12 类别 · 70 模块

| 类别 | 模块数 | 最常用 |
|------|:--:|------|
| Auth | 7 | Auth, Csrf, ApiKeyAuth |
| Security | 5 | SsrfGuard, SecurityHeaders, BotDetector |
| Tracking | 18 | PostbackDispatcher, HttpSender, RefundDispatcher |
| SaaS | 9 | TenantManager, TenantContext, BillingGate |
| Observability | 5 | EventStore, AlertNotifier, StructuredLogger |
| Resilience | 3 | RetryHandler, CircuitBreaker |
| Services | 5 | EmailService, SettingsManager, DbSessionHandler |
| Entity | 9 | ApiKey, FacebookCapiIntegration, GoogleAdsIntegration |
| Stats | 5 | CampaignStatsService |
| Integration | 6 | GoogleAdsInsightsClient, GeoResolver |
| CLI Scripts | 5 | retry-dead-letters, monitor-health, backup |
| Config Gates | 8 | SECURITY_PRODUCTION_MODE → 全部子开关 |

## 模块注册四原则

1. **命名空间映射**: `Converge\<Category>\<ClassName>` → `src/<Category>/<ClassName>.php`
2. **依赖注入**: 所有模块通过构造函数注入 `mysqli $db`，不隐藏全局状态
3. **纯函数优先**: `UrlBuilder::replace()`, `RefundPolicy::validate()`, `StateNormalizer::normalize()` — 零 mock 可测
4. **门控开关**: 安全功能统一受 `SECURITY_PRODUCTION_MODE` 控制，开发默认关

## 相关记忆
- [[converge-session-summary]]
- [[email-service-capability]]
- [[converge-p0-p2-security-patches]]
