# Latte 模板最佳实践 — 安全·分离·语义化

> 层: L3 领域规格 | 版本: v1.0 | 适用: Converge 项目所有 Latte 模板
> 来源: 39模板迁移实战 + Latte 官方文档 + 行业最佳实践

## 一、语法安全（最高优先级）

### 规则1: CSS/JS 语法隔离

```latte
<!-- ✅ 正确: 所有 <style> 块必须包裹 {syntax off} -->
{syntax off}<style>
.card { color: var(--content-primary); border: 1px solid var(--border-default); }
</style>{/syntax}

<!-- ✅ 正确: 纯 JS 脚本必须包裹 {syntax off} -->
{syntax off}<script type="module">
import { Application } from "@hotwired/stimulus";
import MyController from "./controllers/my_controller.js";
window.Stimulus = Application.start();
window.Stimulus.register("my", MyController);
</script>{/syntax}
```

### 规则2: 禁止 {do echo} 反模式

```latte
<!-- ❌ 错误: {do} 是赋值宏，不能用于输出 -->
{do echo $pageHeader|noescape}

<!-- ✅ 正确: 用 {=expr} 或 {$var} 输出 -->
{=$pageHeader|noescape}
```

### 规则3: Latte 变量上下文感知转义

```latte
<!-- ✅ 默认转义（防 XSS） -->
{$userInput}

<!-- ⚠️ 仅对预编译的 HTML 使用 noescape -->
{$preRenderedHtml|noescape}

<!-- ❌ 禁止: 对用户输入使用 noescape -->
{$userComment|noescape}
```

### 规则4: null 安全

```latte
<!-- ✅ 过滤前加 null guard -->
{($amount ?: 0)|number:2}
{ucfirst($name ?: '')}
```

---

## 二、布局与继承

### 规则5: 强制使用 {layout} + {block}

```latte
{* ✅ 页面模板 *}
{layout 'templates/_layouts/dashboard.latte'}

{block title}Dashboard{/block}

{block content}
  <div class="main-content">
    {=$pageHeader|noescape}
  </div>
{/block}
```

### 规则6: 组件复用用 {include}

```latte
{* 简单组件 *}
{include 'templates/_components/button.latte', label => '提交', type => 'submit'}

{* 带插槽的组件 *}
{include 'templates/_components/card.latte'}
  {block cardTitle}标题{/block}
  {block cardBody}内容{/block}
{/include}
```

---

## 三、禁止模式

| ❌ 禁止 | ✅ 正确 |
|------|------|
| `<style>` 块外无 `{syntax off}` | 必须包裹 |
| `<script>` 块外无 `{syntax off}` (纯JS) | 必须包裹 (含`{$var}`除外) |
| `{do echo $var}` | `{=$var}` |
| `{ldelim}` / `{rdelim}` (Latte 3 已移除) | 用 `{syntax off}` 包裹 |
| `$var@iteration` (Smarty语法) | `$iterator->counter` |
| `$var|number` 无 null guard | `($var ?: 0)|number` |
| `ucfirst($var)` 无 null guard | `ucfirst($var ?: '')` |
| 模板中写复杂业务逻辑 | 提取到 PHP UseCase |
| 直接输出 `{$userInput|noescape}` | 默认用 `{$userInput}` (自动转义) |
| 硬编码颜色/间距 | `var(--xxx)` CSS 变量 |

---

## 四、提交前门禁

每次提交 .latte 文件前，自动运行：

```bash
# 编译检查 (pre-commit hook 自动执行)
php data/source/scripts/test-latte-compile.php

# 语法保护修复 (idempotent，可反复运行)
php data/source/scripts/fix-latte-script-syntax.php

# 编译错误自动修复
php data/source/scripts/fix-latte-compile-errors.php
```

## 五、L3 自证标记

每个模板底部必须有自证标记（自动注入）：

```latte
<!-- L3:OK dashboard -->
```

由 `scripts/inject-template-assertions.php` 自动维护。

---

## 六、L2 组件调用约定（PHP 类 vs Latte 模板）

> 补充1: 解决团队在"Latte 组件"和"PHP 组件"之间的选择困惑

| 组件类型 | 实现方式 | 示例 | 选择原因 |
|---------|------|------|------|
| **原子组件** (Button, Input, Badge, Spinner) | PHP 类 `Xxx::render()` | `{=Button::render('提交', ['variant' => 'primary'])}` | 需类型安全 + 属性校验 |
| **分子组件** (StatCard, DataTable, EmptyState) | PHP 类 `Xxx::render()` | `{=StatCard::render($stat)}` | 需数据格式化 + 状态管理 |
| **有机体** (SidebarNav, CampaignCard) | PHP 类 `Xxx::render()` | `{=SidebarNav::render($menu)}` | 需业务逻辑封装 |
| **布局片段** (header, footer, sidebar shell) | Latte `{include}` | `{include '_partials/header.latte'}` | 纯 HTML 骨架，无业务逻辑 |
| **简单模板片段** (icon, label, divider) | Latte `{define}` + `{include}` | `{include #badge, text => 'NEW'}` | 无逻辑，纯渲染 |
| **页面特定片段** (dashboard widgets) | Latte `{include}` | `{include '_widgets/stats-row.latte'}` | 页面级组合 |

### 决策树

```
需要类型校验/属性白名单/单元测试？ → PHP 类
需要数据格式化/状态枚举/错误处理？ → PHP 类
需要业务逻辑/权限判断/审计追踪？  → PHP 类
──────────────────────────────────
纯 HTML 骨架/布局/简单复用？       → Latte {include}
```

### 调用规范

```latte
{* ✅ PHP 组件: 用 {=expr} 输出 *}
{=Grid::row([...])|noescape}
{=StatCard::render($data)|noescape}

{* ✅ Latte 片段: 用 {include} *}
{include '_partials/sidebar.latte', menu => $menu}
```

---

## 七、可追溯集成（EventStore + data-track）

> 补充2: 让模板层的用户操作可追溯、可审计

### 规则7: 关键操作必须包含 data-track 属性

所有用户操作（按钮点击、表单提交、链接跳转）必须带上追踪标记：

```latte
<!-- ✅ 按钮操作追踪 -->
<button data-track="campaign:create:click"
        data-track-params='{"source":"sidebar"}'
        @click="createCampaign()">
  新建广告
</button>

<!-- ✅ 表单追踪 -->
<form data-track="login:submit" method="post">

<!-- ✅ 批量操作追踪 -->
<a data-track="campaign:export:csv"
   data-track-context="{id: {$campaignId}}"
   href="/export.php?id={$campaignId}">
  导出
</a>
```

### 追踪命名规范

```
{domain}:{action}:{trigger}

data-track="campaign:create:click"    ← 域:动作:触发
data-track="conversion:export:button"
data-track="funnel:delete:confirm"
```

### 后端接收

```js
// command-tracker.js 自动捕获 → EventStore
document.addEventListener('click', e => {
    const track = e.target.closest('[data-track]');
    if (track) {
        fetch('/api/track', {
            method: 'POST',
            body: JSON.stringify({
                action: track.dataset.track,
                params: track.dataset.trackParams,
                context: track.dataset.trackContext,
                url: location.href,
                timestamp: new Date().toISOString(),
            }),
        });
    }
});
```

---

## 八、编译缓存策略

> 补充3: 开发/生产环境区别对待

### 规则8: 环境感知的缓存配置

```php
// src/UI/LatteEngine.php
$latte->setTempDirectory(
    ($_ENV['APP_ENV'] ?? 'dev') === 'prod'
        ? '/tmp/latte'            // 生产: 持久缓存
        : __DIR__ . '/../cache/latte'  // 开发: 可手动清理
);

// 生产环境: 开启自动刷新检测
if (($_ENV['APP_ENV'] ?? 'dev') === 'prod') {
    $latte->setAutoRefresh(false);  // 不自动检查模板修改 (性能)
}
```

| 环境 | 缓存目录 | AutoRefresh | 清理方式 |
|------|------|:---:|------|
| 开发 | `cache/latte/` | true (立即生效) | `rm -rf cache/latte/*` |
| 生产 | `/tmp/latte/` | false | 部署时清理 + 定时清理 |

### 部署时清理

```bash
# Docker entrypoint / deploy script
rm -rf /tmp/latte/*
php /var/www/converge/scripts/test-latte-compile.php  # 预热所有模板
```

---

## 九、L3 自证标记自动化门禁

> 补充4: 标记缺失或文件名不符 → 阻断提交

### 规则9: 每个 .latte 文件必须有 L3 自证标记

```bash
# pre-commit hook 自动验证
php scripts/verify-template-assertions.php --strict
# 检查项:
#  1. 每个 .latte 必有 <!-- L3:OK {name} -->
#  2. {name} 必须匹配文件名
#  3. 标记不能被注释包裹
# 任一失败 → 阻断提交
```

### 验证脚本

```php
// scripts/verify-template-assertions.php
foreach (glob('templates/pages/*.latte') as $file) {
    $content = file_get_contents($file);
    $name = basename($file, '.latte');

    if (!preg_match('/<!-- L3:OK ' . preg_quote($name, '/') . ' -->/', $content)) {
        echo "❌ $file: 缺少 L3:OK 标记或名称不匹配 (期望: L3:OK $name)\n";
        echo "   运行: php scripts/inject-template-assertions.php\n";
        exit(1);
    }
}
echo "✅ 所有模板 L3 自证标记正确\n";
```

### 注入命令

```bash
# 自动为所有模板注入/更新 L3 标记 (idempotent)
php scripts/inject-template-assertions.php

# 验证
php scripts/verify-template-assertions.php --strict
```

---

## 十、与四层架构的关系

```
L4 Page    → 调用 LatteEngine::display()              (data-track → EventStore)
L3 Template → .latte 文件 (本规则)                      (L3:OK 标记 ✓)
L2 Component → PHP 类 render() 或 Latte {include}       (决策树 ↑)
L1 Token   → tokens.css CSS 变量                        (禁止硬编码)
```

### 与六可理念的对应

| 六可 | 本规则映射 | 实现 |
|------|------|------|
| 可观察 | L3 自证标记 + 编译检查 | `test-latte-compile.php` |
| 可追溯 | data-track → EventStore | `command-tracker.js` |
| 可审计 | 自证标记门禁阻断 | `verify-template-assertions.php` |
| 可验证 | 四层断言 (L1-L4) | `LayerAssertion` + `SelfProver` |
| 可进化 | 组件决策树 | PHP 类 vs Latte 片段 |
| 可自愈 | 5 类修复脚本 | `fix-latte-*.php` |
