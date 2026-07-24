---
name: cdn-blocked-china-alpine-htmx
description: unpkg.com CDN 在国内被拦截→Alpine未定义→侧边栏点击无反应
metadata: 
  node_type: memory
  type: feedback
  originSessionId: c898435f-e7cd-482c-9fbb-6adbb847449c
---

# CDN 被拦截 — Alpine.js/HTMX 未加载

**检测模式**: 浏览器 Console 报 `Alpine is not defined` + 页面交互无响应 (侧边栏点不动)

**根因**: `unpkg.com` CDN 在国内网络环境不稳定, Alpine.js 和 HTMX 无法加载。所有依赖 Alpine 的交互组件 (`@click`/`x-data`) 静默失效。

**现象链**:
1. `Alpine is not defined` → 侧边栏按钮无反应
2. `[Converge] Frontend error: Script error. 0` → error-capture.js 捕获 CORS 隐藏的错误
3. 页面静态渲染正常, 但所有交互功能失效

**修复**:
1. 下载 Alpine.js 到 `public/assets/js/alpinejs.min.js` (46KB)
2. 下载 HTMX 到 `public/assets/js/htmx.min.js` (51KB)
3. 模板从 `<script src="https://unpkg.com/alpinejs@3">` 改为 `<script src="/assets/js/alpinejs.min.js?v=3">`
4. 同样处理 HTMX

**预防**: `project-scaffold.sh` 已自动下载 Alpine.js 到本地。新项目不依赖 CDN。

**关联**: [[docker-deploy-error-patterns]] [[converge-php-alpine-htmx-architecture-patterns]]
