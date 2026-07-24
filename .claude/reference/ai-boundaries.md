# AI 行为边界 — 触碰即违规

> 定位: AI 是刚入职的高级实习生 — 语法正确 ≠ 业务正确。
> 以下 6 条规则自动注入到每个 AI 生成任务中。

## ① 零信任转义 — XSS 防线

| ❌ 违规 | ✅ 正确 |
|--------|--------|
| `{$user->name\|noescape}` | `{$user->name}` (Latte 自动转义) |
| `{$campaign->description\|noescape}` | `{=$preRenderedHtml\|noescape}` (仅 `_html` 后缀 + 预编译 HTML) |

**规则**: `|noescape` 仅允许用于变量名包含 `_html` 后缀（如 `$cardHtml`、`$tableHtml`），且该变量值由 PHP 组件 `::render()` 生成。用户输入绝对禁止 `|noescape`。

## ② 单次请求单 SQL — N+1 防线

```php
// ❌ 违规: N+1 查询
foreach ($conversions as $c) {
    $user = $db->query("SELECT * FROM users WHERE id = {$c['user_id']}");
}

// ✅ 正确: JOIN 一次完成
$rows = $db->query("SELECT c.*, u.name AS user_name FROM conversions c LEFT JOIN users u ON c.user_id = u.id");
```

**规则**: 控制器内任何一个 `foreach` 循环体里禁止出现数据库查询。数据关联必须在 SQL JOIN 中一次完成。

## ③ 组件准入 — 防过度抽象

**规则**: 仅在 ≥3 个页面复用同一 UI 元素时，才允许提炼为独立组件放入 `app/UI/`。单页专用的 UI 直接写在模板内或 Controller 中。

```
一次引用 → 写在模板里
两次引用 → 写在模板里，观察是否需要提取
三次引用 → 提取为 app/UI/{Component}.php
```

## ④ 边界暴露 — 空值防护

```php
// ❌ 违规: 无空值保护
echo $pagination->nextPage();

// ✅ 正确: 空数据/最大页/单条数据 三个边界
$page = max(1, min($page, $totalPages ?: 1));
```

**规则**: 所有分页、数组索引、数学运算必须包含空值守卫（`??` 或 `?:`）。生成后验证 3 个边界：空数据、最大页、单条数据。

## ⑤ 版本锁定 — 防 API 幻觉

本项目技术栈: **PHP 8.2 · Stimulus 3 · ECharts 5 · Latte 3 · MySQL 8.0**

**禁用**: `mysql_*` 函数 · `{ldelim}` Latte 2 语法 · 未验证的第三方 CDN · jQuery

**推荐**: 参考项目已有代码 (`public/admin-panel.php`、`templates/pages/dashboard.latte`) 作为标准写法。

## ⑥ SDUI 架构锁定 — 防上下文漂移

```text
本项目的 UI 架构不可更改为:
  ❌ React / Vue / Svelte 单页面应用
  ❌ 前端 fetch() API 直连数据库
  ❌ 全客户端渲染 (CSR)

唯一合法管道:
  PHP Controller → 组件 HTML → Latte 模板 → |noescape 输出 → Stimulus 行为驱动
```

**规则**: 每次新对话开始时，AI 必须先读 CLAUDE.md。任何偏离 SDUI 管道的建议直接驳回。

---

## 全局禁止模式 (TDA 全层)

| ❌ | ✅ | 层 |
|----|----|:---:|
| 模板里写 SQL 查询 | PHP 控制器查询 → 传变量到模板 | T |
| 模板里写复杂业务逻辑 | 提取为 UseCase → D 层调用 | T |
| Stimulus 里 `fetch()` 直调 API | D 层注入 `window.__DATA` → A 层读取 | A |
| 裸 `echo '<div>'` 输出 HTML | 组件 `::render()` + Latte | D |
| 内联 `style="color:#xxx"` | `var(--color-*)` 令牌 | D |
| 跨 Controller 直接调方法 | DOM `dispatchEvent(new CustomEvent(...))` | A |
| 一个文件做两件事 | 拆分到两个文件 (描述无"和"字) | 全部 |

---

## 验证清单

- [ ] `php -l` 语法全通过
- [ ] Pre-commit 14 步门禁通过
- [ ] Domain 零 IO · 跨模块仅 Hooks · 文件 ≤150 行
- [ ] bootstrap.php 菜单自注册生效
- [ ] 新页面走 SDUI 管道: Controller → Component HTML → Latte → |noescape
- [ ] Stimulus Controller 覆盖 loading / error / empty 三态
- [ ] 数据注入用 `JSON_HEX_APOS | JSON_HEX_TAG` + 模板 `|noescape`
- [ ] 新增 PHP 类后运行 `gen-classmap.php --write` + `composer dump-autoload`
