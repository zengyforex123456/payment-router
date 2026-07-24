---
name: alpine-php-dock-btn-missing-click
description: Alpine @click not rendered when PHP function generates HTML buttons
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# Alpine + PHP: dockBtn() 按钮无点击反应

**检测模式**: Alpine 组件方法存在但按钮点击无反应 / 侧边栏点不动 / `switchDock is not defined`

**根因**: PHP 函数 `dockBtn()` 用 heredoc 输出 `<button class="dock-btn" data-dock="xxx">`，但未包含 Alpine 指令 `@click` 和 `:class`。Alpine 的 `x-data` 容器存在且 `switchDock()` 方法正确，但 DOM 按钮上没有任何事件绑定。

**修复**:
```php
// Before — 无 Alpine 绑定
<button class="dock-btn" data-dock="{$dock}">

// After — 添加 @click + :class
<button class="dock-btn" data-dock="{$dock}"
        @click="switchDock('{$dock}')"
        :class="{ 'active': dock === '{$dock}' && open }">
```

**验证**: 
```bash
php scripts/test-navigation.php --base=http://137.184.225.93
# 23/23 pages HTTP 200
```

**预防**: PHP 函数生成 HTML 时如果该 HTML 在 Alpine `x-data` 容器内，必须在函数输出中包含 `@click`/`x-show`/`:class` 等 Alpine 指令。不能假设 Alpine 会通过 `data-*` 属性自动发现绑定。

**关联**: [[converge-dev-patterns]]
