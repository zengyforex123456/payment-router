---
name: login-page-footer-flex-row-layout-bug
description: Login page footer renders beside card instead of below due to missing flex-direction
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# 登录页 Footer 在卡片右侧而非底部

**检测模式**: 登录页 Docs/Terms/Privacy 链接出现在卡片右侧，而非底部居中

**根因**: `body { display: flex; align-items: center; justify-content: center; }` 默认 `flex-direction: row`。登录卡片和 footer div 作为 flex 子元素被并排渲染。

**修复**: 加 `flex-direction: column`：
```css
body {
  display: flex;
  flex-direction: column;  /* ← 关键 */
  align-items: center;
  justify-content: center;
}
```

**验证**: Footer 链接在卡片下方居中显示。顶部 language/theme 按钮使用 `position: fixed` 不受影响。

**Why**: `display: flex` 默认主轴为 row。登录卡片 + footer 两个 div 水平排列。顶栏用了 fixed 脱离流。

**How to apply**: 所有居中布局页面检查 `flex-direction`，尤其是多子元素的 body。
