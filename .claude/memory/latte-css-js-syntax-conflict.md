---
name: latte-css-js-syntax-conflict
description: "Latte {letter} 与 CSS/JS 语法冲突的三种官方解决方案和自愈策略"
metadata: 
  node_type: memory
  type: reference
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# Latte 模板 CSS/JS 语法冲突

> 来源: [Latte 官方文档 Tips and Tricks](https://latte.nette.org/en/recipes#toc-editors-and-ide)
> 根因: Latte 将 `{letter` (如 `{color:`, `{id:`, `{h.classList`) 解析为宏标签

## 三种官方方案

| 方案 | 语法 | 适用场景 | 推荐度 |
|------|------|------|:---:|
| **A. 空格转义** | `body { color: blue }` 在 `{` 后加空格/换行/引号 | 少量 CSS/JS, 需保留 Latte 变量 | ⭐⭐ |
| **B. n:syntax="off"** | `<style n:syntax="off">` / `<script n:syntax="off">` | 纯 CSS/JS, 无需 Latte 变量 | ⭐⭐⭐ 最佳 |
| **C. n:syntax="double"** | `n:syntax="double"` → Latte 用 `{{$var}}`, JS 用 `{key:val}` | 混合场景 (JS里需用Latte变量) | ⭐⭐ |

## 方案 B 详解 (推荐)

```html
<!-- 纯 CSS → n:syntax="off" -->
<style n:syntax="off">
  body { color: blue; }
  .card { border: 1px; transition: all .15s; }
</style>

<!-- 纯 JS → n:syntax="off" -->
<script n:syntax="off">
  if (saved === 'dark') { h.classList.add('dark'); }
</script>
```

## 方案 C 详解 (混合场景)

```html
<!-- JS 含 Latte 变量 → n:syntax="double" -->
<script n:syntax="double">
  Alpine.data('form', () => ({
    email: {{$emailJson}},      // ← Latte 用双花括号
    error: {{$errorJson}},
  }));
  if (x) { doStuff(); }         // ← JS 用单花括号
</script>
```

## 自愈策略

在 `fix-latte-script-syntax.php` 中实现:
```
1. 检测 <style> 块 → 自动添加 n:syntax="off"
2. 检测 <script> 块:
   a. 无 {${var} → 自动添加 n:syntax="off"
   b. 有 {${var} → 自动添加 n:syntax="double" + {${var} → {{${var}}}
3. 验证: LatteEngine::compile() 无异常
```

## 注意事项

- `{/syntax}` 关闭的是 `{syntax off}` 块，不是 `n:syntax` 属性
- `n:syntax="off"` 只在当前 HTML 元素内生效
- `{syntax off}` 是标签版本，作用域到 `{/syntax}`
- [[latte-double-underscore-filter-rejected]]
