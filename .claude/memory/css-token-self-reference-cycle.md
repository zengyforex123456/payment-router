---
name: css-token-self-reference-cycle
description: CSS var() self-reference cycle breaks all dependent tokens — silent 1.2:1 contrast
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# CSS 令牌自引用死循环：`--surface-raised: var(--surface-raised)`

**检测模式**: 所有 `text-content-inverse` / `bg-surface-raised` 元素对比度极低（~1:1），文字几乎不可见

**根因**: `tokens.css` 中 `--surface-raised: var(--surface-raised)` 自引用。CSS 自定义属性的循环依赖会导致该属性"computed-value invalid"——浏览器当作 `unset` 处理，继承父元素值。`--content-inverse: var(--surface-raised)` 随之失效。

**修复**: `--surface-raised: #ffffff`（light mode 卡片/弹层底色应为白色）

**验证**: 
- CTA 按钮 `text-content-inverse` on `bg-accent`: 1.2:1 → 11.5:1 (AAA)
- `grep 'var(--[^)]*)\s*;\s*/\*' tokens.css` 检查所有自引用

**与检测工具的关系**: `check-contrast.php` 静态解析 `var()` 依赖 `loadTokens()` —— 如果令牌本身是循环引用，解析器拿不到值 → 被跳过。修复后可正常检测。
