---
name: six-cap-framework-injection
description: 六可不应每个工具重复实现 — ToolContext+ToolRunner 框架层注入 (Sidecar模式)
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 88edd302-119f-44ea-bb1f-3266751e0c7d
  modified: 2026-07-19T06:28:09.506Z
---

# 六可作为框架基础设施 — ToolContext + ToolRunner

**核心理念**: 六可（可观察、可追溯、可审计、可验证、可进化、可自愈）不是工具的附加功能，而是工具执行框架的内在属性。

**类比**: 微服务的 Sidecar 模式 —— 每个服务不自己实现日志/监控/追踪，由 Envoy Proxy 注入。

## 架构

```
ToolRunner.run("deploy", params)
  │
  ├─ 1. 创建 ToolContext (六可注入)
  │     sessionId, logger, eventStore, audit, autoHeal
  │
  ├─ 2. 加载 + 校验
  │
  ├─ 3. execute(ctx, params)  ← 工具只写业务逻辑
  │     ctx->log(), ctx->ok(), ctx->error()
  │
  ├─ 4. 自动记录事件 (JSONL 不可变日志)
  │     commitEvent(ok, elapsed, details, error)
  │
  └─ 5. 自愈 (if !ok && autoHeal)
        tool->heal(params, result)
```

## ToolContext API

| 方法 | 六可维度 | 用途 |
|------|------|------|
| `$ctx->log($msg)` | 🔭 可观察 | 结构化日志 (带时间戳+耗时) |
| `$ctx->ok($msg)` | 🔭 可观察 | 成功日志 |
| `$ctx->error($msg)` | 🔭 可观察 | 错误日志 |
| `$ctx->warn($msg)` | 🔭 可观察 | 警告日志 |
| `$ctx->sessionId` | 📋 可追溯 | 唯一会话 ID |
| `$ctx->getAuditData()` | 📐 可审计 | git user+branch+hash+hostname |
| `$ctx->elapsed()` | ✅ 可验证 | 执行耗时 |
| `$ctx->commitEvent()` | 📋📐 | 自动化事件记录 (框架调用) |
| `ToolContext::history()` | 📋📐 | 跨工具事件历史查询 |

## 关键设计决策

**❌ 方案B: Trait (每个工具 use SixCapable)**
- 每个工具需重复 use
- 无法统一控制执行流程
- 新增能力需修改所有工具

**✅ 方案A: ToolContext 注入 (框架层)**
- 工具零侵入，只写业务逻辑
- ToolRunner 统一管理生命周期
- 新增能力只需改 ToolRunner
- Mock ToolContext 即可测试

**验证**: 17 工具通过 ToolRunner 执行，`storage/tool-events.jsonl` 自动记录所有事件
