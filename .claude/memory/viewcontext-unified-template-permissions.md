---
name: viewcontext-unified-template-permissions
description: ViewContext 值对象解决 Latte 模板权限上下文缺失和 Props Drilling
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# ViewContext — 统一视图上下文

**检测模式**: Latte 模板中 `$perm->` / `hasPermission` / `$navCan` 搜索返回零匹配
**根因**: PHP 视图通过 `$GLOBALS['permission']` 传递权限，Latte 走独立渲染管道，权限上下文未注入
**影响**: Latte 渲染的页面可能泄露 admin-only UI 元素给 viewer

**修复**:
1. 创建 `src/UI/ViewContext.php` — 值对象含 `user` + `can()` + `canAny()` + `isAdmin()` + `roles` + `locale`
2. `ViewContext::fromGlobals()` — 从 `$_SESSION`/`$GLOBALS` 自动构建
3. `LatteEngine::render()` 自动注入 `$context` (ViewContext) + `$user` 到每个模板
4. Latte 模板: `n:if="$context->can('campaign.create')"` 守卫敏感 UI

**验证**: 47 tests, 318 assertions; `experiments.latte` 编译通过
**关键**: 调用方可通过 `$params` 覆盖默认值 (`$params['context']`)；无权限时 `can()` 返回 false（安全优先）
