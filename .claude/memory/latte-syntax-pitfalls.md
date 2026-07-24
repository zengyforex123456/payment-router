---
name: latte-syntax-pitfalls
description: "Three Latte syntax pitfalls — {return}, {!empty}→{!!empty} regex bug, JS braces"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# Latte 语法 3 个陷阱

## 1. `{return}` 不是有效标签
- **错误**: `{if empty($arr)} {return} {/if}` → CompileException
- **正确**: `{if !empty($arr)}...content...{/if}` 包裹内容

## 2. 正则替换 `!empty` 破损
- 将 `$var.key` → `$var['key']` 时，`{if !empty($var.cards)}` 被替换为 `{if !!empty($var['cards'])}`（多了一个 `!`）
- `!!empty(...)` = `!(!empty(...))` = 条件反转 → 内容永不渲染
- **防御**: 正则替换后必须 `grep '!!empty'` 检查

## 3. JavaScript `{}` 被 Latte 解析
- `<script>tailwind.config={darkMode:'class'...` → Latte 把 `{darkMode` 当标签
- **修复**: 用 `{syntax off}<script>...JS...</script>{/syntax}` 包裹
- Alpine `x-data` 中的 `{slug:'main'}` 同理
- **防御**: 所有含 `{}` 的 `<script>` 块必须 `{syntax off}`

## 4. `{include}` 路径是相对当前模板目录
- 模板在 `templates/pages/landing.latte` → `{include 'templates/landing/_nav.latte'}` 解析为 `templates/pages/templates/landing/_nav.latte`
- **正确**: `{include '../landing/_nav.latte'}` 用相对路径
