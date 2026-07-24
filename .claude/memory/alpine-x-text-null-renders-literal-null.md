---
name: alpine-x-text-null-renders-literal-null
description: "Alpine.js x-text renders the string \"null\" when value is JSON null"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# Alpine.js `x-text` 渲染字面量 "null"

**检测模式**: 页面显示 "⚠️ null" — Alpine `x-text="error"` 渲染了字符串 "null"

**根因**: PHP `json_encode(null)` 输出字面量 `null`（JavaScript 原始值）。但当 Alpine 的 `x-text` 绑定到一个 JS `null` 值时，它会被转换为字符串 `"null"` 显示在页面上。

**症状**: 登录页加载后显示 `⚠️ null`，即使没有错误。`php -l` 语法检查通过，页面无 500 错误。

**修复**: 用空字符串替代 null：
```php
// ❌ Alpine 渲染 "null"
'errorJson' => json_encode($error ?: null),

// ✅ Alpine 渲染空字符串 (x-show 自动隐藏)
'errorJson' => json_encode($error ?: ''),
```

模板配合：
```html
<div class="error-msg" x-show="error" x-text="error"></div>
<!-- error='' 时 x-show 求值为 false → 自动隐藏 -->
```

**验证**: 无错误时页面不显示任何 error 消息。

**Why**: `json_encode(null)` ≠ `""`。Alpine `x-text` 调用 `.toString()` 将 null 转为 "null"。

**How to apply**: 所有 PHP→Alpine 数据传递使用 `json_encode($value ?: '')` 而非 `json_encode($value ?: null)`。
