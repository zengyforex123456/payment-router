---
name: main-grid-unclosed-footer-trapped
description: Landing page footer trapped in 12-col grid because <main> never closed
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# Landing Page Footer 挤成窄条：`<main>` 未关闭

**检测模式**: 页面底部 footer/版权文字挤在左侧窄条·非全宽显示

**根因**: landing.php 中 `<main class="grid grid-cols-12">` 只有开标签没有关标签。footer 通过 `_layout-foot.php` 引入时落在 `<main>` 内部，成为 12 列网格的子元素。由于 footer 没有 `col-span-full` 类，只占 1 列宽度。

**修复**:
1. 在 `include '_layout-foot.php'` 之前加 `</main>` 关闭网格
2. 清除 Grid::container() 重构遗留的 stray `</div>`
3. 组件内部不要依赖父级隐式 `col-span-full`，页面编排层负责打开/关闭网格

**验证**: `php -l landing.php` 语法通过，浏览器 footer 恢复全宽显示

**相关知识**: [[grid-system-parent-dependency]] [[landing-page-structure]]
