---
name: latte-error-patterns-complete
description: Latte 模板 5 类编译/运行时错误指纹·根因·修复·自愈策略
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# Latte 模板错误指纹全集

> 来源: Converge 39模板 Latte 迁移实战 | 日期: 2026-07-17

## 错误1: {do echo} 反模式

**检测模式**: `Latte\CompileException: Unexpected '...', expecting end of tag in {do}`

**根因**: `{do}` 是 Latte 赋值宏 (`{do $var = expr}`)，不能用于输出。`{do echo $var}` 编译成 `<?php echo echo $var; ?>` — PHP 语法错误。

**修复**: `{do echo $expr|noescape}` → `{=$expr|noescape}`

**影响**: 7 个模板 (affiliate, analytics, commissions, conversions, dashboard, snapshot-viewer, subscription)

**自愈脚本**: `preg_replace('/\{do\s+echo\s+(.+?)\}/', '{=$1}', $content)`

---

## 错误2: CSS/JS {letter} 语法冲突

**检测模式**: `Latte\CompileException: Unexpected '{'` 或 `Unexpected ';'` 在 `<style>`/`<script>` 内

**根因**: Latte 将 `{` 后紧跟字母的模式 (如 `{color:`, `{h.classList`) 解析为宏标签

**修复**: 
- 方案 A (最佳): `<style n:syntax="off">` / `<script n:syntax="off">`
- 方案 B: `{syntax off}<style>...</style>{/syntax}`
- 方案 C: CSS/JS 内 `{ ` → `{ ` (加空格)

**影响**: 14 个模板

**自愈脚本**: `scripts/fix-latte-script-syntax.php` (idempotent)

**参考**: [[latte-css-js-syntax-conflict]]

---

## 错误3: {literal_text} 在代码示例中

**检测模式**: `Latte\CompileException: Unexpected tag {xxx}` (不在 Latte 上下文)

**根因**: `<pre><code>` 中的 URL 占位符 `{campaign_id}` 被当作 Latte 标签

**修复**: 用 `{syntax off}<pre><code>...{/syntax}` 包裹

**注意**: `{ldelim}`/`{rdelim}` 在 Latte 3 已移除，不可用

**影响**: docs.latte (6 个占位符)

---

## 错误4: Smarty @iteration → Latte $iterator

**检测模式**: `Latte\CompileException: Unexpected '@'` 在 `{foreach}` 内

**根因**: Smarty 语法的 `$var@iteration` 在 Latte 中无效。Latte 用 `$iterator->counter`

**修复**: 
- `$var@iteration` → `$iterator->counter` (1-based)
- `$var@first` → `$iterator->first`
- `$var@last` → `$iterator->last`

**影响**: experiments.latte (line 70)

---

## 错误5: null→filter 类型错误

**检测模式**: `TypeError: Latte\Essential\Filters::number(): Argument #1 ($number) must be of type float, null given`

**根因**: PHP 8.1+ 严格类型，Latte filter 不接受 null。模板变量未设置时传入 null

**修复**:
- `$var|number` → `($var ?: 0)|number`
- `ucfirst($var)` → `ucfirst($var ?: '')`

**影响**: affiliate, funnel, subscription, case-studies

**自愈脚本**: 
```php
preg_replace('/\$([a-zA-Z_]+)\|number(?!\w)/', '(\$$1 ?: 0)|number', $content);
preg_replace('/ucfirst\(\$([a-zA-Z_]+)\)/', 'ucfirst(\$$1 ?: \'\')', $content);
```

---

## 错误6: Latte `__` 过滤器名被拒

**检测模式**: `LogicException: Invalid filter name '__'`

**根因**: Latte 将 `__` 开头的 Filter 名视为保留。php -l 检查通过但运行时崩溃。

**修复**: `|__` → `|t` (alias)

**参考**: [[latte-double-underscore-filter-rejected]]
