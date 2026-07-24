---
name: latte-dot-is-concatenation-not-property
description: "Latte {$arr.key} means string concat $arr . 'key', NOT array access"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# Latte `{$var.key}` 是字符串拼接，不是数组访问

**检测模式**: PHP Warning: "Array to string conversion" → 输出 `Arraykey` 而非数组值

**根因**: Latte 模板中 `.` 运算符是**字符串拼接**（同 PHP），不是对象属性/数组键访问。`{$page.title}` 编译为 `$page . 'title'` 而非 `$page['title']`。

**修复**: 所有数组访问用 `{$var['key']}`，对象属性用 `{$var->prop}`

**自动化**: `scripts/fix-latte-dot-notation.php` 批量替换 `{$var.key}` → `{$var['key']}`

**验证**: `grep '\$\w+\.\w+' templates/**/*.latte` 应为空
