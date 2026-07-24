---
name: alpine-js-not-loaded-dashboard-layout
description: "Alpine.js dead on dashboard — CDN loaded only in public layout, not v2.php"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# Alpine.js 未加载 — Dashboard 所有交互失效

**检测模式**: 
- 点击 Dock 按钮无反应、Ctrl+K 无反应、? 无反应
- 浏览器 Console: `Alpine is not defined` 或 `Alpine.data is not a function`
- 页面 HTML 中有 `x-data` / `@click` 但完全不起作用

**根因**: Converge 有两套布局:
- `public/_layout-head.php` — 公共页 (landing/login/register/pricing) → 加载了 Alpine CDN ✅
- `views/layout/v2.php` — 看板/Dashboard 页 → **没有加载 Alpine CDN** ❌

`v2.php` 中调用了 `Alpine.data('dockNav', ...)` 和 `Alpine.data('cmdPalette', ...)`，但因为 Alpine.js 从未被加载，这些调用静默失败，所有 `x-data`/`@click`/`x-show` 指令被忽略。

**修复**:
```html
<!-- v2.php <head> 中添加 -->
<script defer src="https://unpkg.com/alpinejs@3"></script>
<script src="https://unpkg.com/htmx.org@2"></script>
```

**验证**:
1. 打开浏览器 DevTools Console
2. 输入 `Alpine.version` → 应返回版本号如 "3.14.x"
3. 输入 `document.querySelector('[x-data]')` → 应返回 Dock 容器元素
4. 点击 Dock 按钮 → 面板应切换

**预防**: 任何新布局模板必须包含 Alpine + HTMX CDN。`_layout-head.php` 和 `v2.php` 共享的脚本应提取到一个公共 include。

**关联**: [[alpine-php-dock-btn-missing-click]] [[converge-dev-patterns]]
