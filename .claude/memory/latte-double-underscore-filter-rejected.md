---
name: latte-double-underscore-filter-rejected
description: Latte Engine rejects __ as filter name at runtime
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# Latte\Engine 拒绝双下划线过滤器名

**检测模式**: `PHP Fatal error: Uncaught LogicException: Invalid filter name '__'`

**根因**: Latte 不允许以 `__` 开头的过滤器名称（保留给内部使用）。`$engine->addFilter('__', ...)` 在 PHP 语法检查 (`php -l`) 时不会报错，但运行时抛出 `LogicException`。

**症状**: 语法检查通过 (php -l 0 errors)，页面部署后 HTTP 500，错误日志显示 `Invalid filter name '__'`。

**修复**: 用 `'t'` 或 `'trans'` 作为过滤器别名。更新所有 `.latte` 模板中的 `|__` 为 `|t`：
```php
// LatteEngine.php
$engine->addFilter('t', fn(string $key) => __($key));   // ✅
$engine->addFilter('trans', fn(string $key) => __($key)); // ✅
```
```latte
{'login.title'|t}  {* ✅ 简短 *}
{'login.title'|trans}  {* ✅ 完整 *}
```

**验证**: 部署后 `curl http://localhost/login-v2.php` 返回 200 + DOCTYPE。

**相关**: [[cdn-blocked-china-alpine-htmx]] — 另一类"php -l 通过但运行时失败"的问题。

**Why**: 语法检查只验证 PHP 语法树，不执行代码。Latte 的过滤器注册在运行时，`php -l` 无法捕获。

**How to apply**: 所有 Latte 模板使用 `|t` 而非 `|__`；预提交门禁增加 Latte 模板编译验证。
