---
name: converge-multitenant-p0-p1
description: Converge多租户隔离P0地基止血+P1回填实录;MySQL无RLS用应用层收口;审计CRITICAL全裸→修复
metadata: 
  node_type: memory
  type: project
  originSessionId: 6b64e325-9230-426f-99f5-4abfed69c26f
---

# Converge 多租户隔离 P0+P1 (2026-07-12)

**背景**: Converge 起源=单租户开源追踪器, 多租户后贴。审计(agent)判定 **CRITICAL 全裸**:
- `tenant_id` 非迁移一等列, 是 `TenantManager::initSchema()` 运行时 `ALTER … DEFAULT 0` 贴的
- 写路径 100% 不写 tenant_id → 全落 tenant 0
- 读路径 69文件/294查询零过滤; UI+API 拿整数id直查无归属 = IDOR
- `TenantManager::tenantFilter()` 收口 helper 存在但0处调用

**核心决策**: MySQL 8 **无原生 RLS**(vs PostgreSQL)。不照搬 PG `CREATE POLICY`, 用**应用层收口(TenantScopedDb) + VIEW WITH CHECK OPTION 背书**实现 RLS 等效。PRD: `.claude/prd-multi-tenant-isolation.md` v2.0(含RBAC上层)。

**P0 地基止血(已部署验证)**:
- R3 `src/SaaS/TenantContext.php` — 租户上下文单一源: `current()`(session)/`forCampaign()`(click从campaign继承,访客无session)/`forClickId()`(conv从click继承)+静态缓存
- R1 迁移079: `src/Database/Migrations/TenantIdFirstClass.php`(PHP幂等迁移,SHOW COLUMNS/INDEX守卫) 补复合索引`(tenant_id,id)`+`created_by`列。**MySQL无`ADD COLUMN IF NOT EXISTS`+列已被运行时加过→必须PHP守卫,不能静态SQL**
- R2 tracker写路径: Redirector×2/RedirectlessTracker×2/ConversionTracker 的INSERT末列补`tenant_id`(占位符`,?`+paramType`i`)
- R2b create路径: 5个Entity(Campaign/Offer/TrafficSource/LandingPage/Network)的create()补`tenant_id`(TenantContext::current)+`created_by`(session user)
- 验收 `verify-tenant-p0.php` PASS=15

**P1 回填(已执行)**: `scripts/backfill-tenant-id.php` 安全设计:
- 默认dry-run; 锚点=`users.tenant_id`; **≥2租户拒绝自动回填(不猜)**; `--tenant=<id>`/`--tenant-email=<x>` 显式覆盖(人工决策=安全)
- 4个都是测试租户→显式归 acme(id=3): campaigns3/offers5/ts6/lp23/net3/clicks108/conv9
- 子表JOIN传播(clicks←campaigns, conv←clicks) + **显式模式孤儿清扫**(无父链的1条conv直接归target)。0残留

**遗留债(未修)**: 服务器`migrations`记录表与schema漂移→`apply-release-upgrade --migrations`卡`012 Duplicate column campaign_key`。今用`scripts/apply-tenant-079.php`定点绕过。正常迁移路径仍坏, 待对齐。

**下一步**: P2 收口层(TenantScopedDb + 5 Entity读路径隔离,真堵IDOR读) + memberships表/管理员三级(RBAC上层)。见 [[converge-session-summary]] [[converge-feature-gating-truth]]。本地文件已改未commit(deploy直推服务器)。
