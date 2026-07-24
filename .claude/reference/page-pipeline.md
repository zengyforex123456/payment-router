# TDA 页面生成管道

> 强制遵循。AI 生成新页面必须走此管道，禁止自由发挥。
> 核心原则: T 只做骨架，D 是唯一真相源，A 只做行为。

## 页面 TDA 管道

```
┌─ D: PHP 控制器 (public/{page}.php) ──────────────────────────┐
│ 1. 数据查询 (通过 db() 工厂)                                   │
│ 2. PHP 组件 HTML 生成 (Badge::render, StatCard::render…)     │
│ 3. JSON 数据注入 (window.__DATA / window.__API → Stimulus)    │
│ 4. LatteEngine::display('pages/{page}', [vars])               │
└───────────────────┬───────────────────────────────────────────┘
                    │ {$vars} + {=$html|noescape}
┌───────────────────▼───────────────────────────────────────────┐
│ T: Latte 模板 (templates/pages/{page}.latte)                   │
│ {layout '../_layout.latte'} 或 '../_layouts/main.latte'       │
│ {block content}…{/block}                                      │
│ {=$preRenderedHtml|noescape}  ← 输出 PHP 组件 HTML             │
│ data-controller="x" data-x-target="y" ← 声明 Stimulus 绑定     │
└───────────────────┬───────────────────────────────────────────┘
                    │ data-* 属性 + window.__DATA
┌───────────────────▼───────────────────────────────────────────┐
│ A: Stimulus 行为 (public/build/js/controllers/)               │
│ connect() → 初始化状态，读 localStorage                        │
│ action() → 响应用户操作，切换 CSS class / 更新 DOM              │
│ 禁止: fetch() 直调 API · 跨 Controller 直调方法                │
└───────────────────────────────────────────────────────────────┘
```

---

## 新页面生成决策树

```
需要新页面?
 ├─ _layout.latte 系 (VS Code Dock 布局)
 │   ├─ 纯展示 (仪表盘/列表/详情)
 │   │   → D: PHP 组件 HTML + T: Latte + A: 无 (纯静态)
 │   │
 │   ├─ 轻交互 (搜索/筛选/Tab/下拉/模态框)
 │   │   → D: PHP 组件 HTML + T: Latte + A: Stimulus Controller
 │   │
 │   └─ 重交互 (拖拽/画布/实时图表)
 │       → D: PHP 注入初始数据 + A: Stimulus + ECharts
 │
 ├─ index.latte 系 (SPA 路由, Stimulus)
 │   → T: {include '_content/xxx-body.latte'} + A: Stimulus Controller
 │
 └─ 纯 API 输出 (JSON)
     → header('Content-Type: application/json') + echo json_encode()
```

---

## D 层: PHP 组件目录与签名

### L1 原子 (app/UI/)

```php
Badge::render(string $text, string $variant)       // 'success'|'warning'|'danger'|'info'|'default'
Button::render(string $text, string $variant, ?string $href)  // 'primary'|'ghost'|'danger'
Input::render(string $name, string $type, string $placeholder)
Spinner::render()
```

### L2 分子 (app/UI/Molecules/)

```php
StatCard::render(array $data)   // ['icon'=>'💰','label'=>'收入','value'=>'$789','change'=>'+12%','trend'=>'up']
DataTable::render(array $headers, array $rows)
EmptyState::render(string $icon, string $title, string $desc, ?string $ctaUrl, ?string $ctaText)
PageHeader::render(string $title, string $subtitle, string $actionsHtml)
MetricCard::render(array $data) // ['value'=>'$789','label'=>'收入','trend'=>'up']
```

### L3 有机体 (app/UI/Blocks/)

```php
Grid::render(array $config)     // ['cols'=>3, 'gap'=>'md', 'children'=>$html]
Card::render(array $config)     // ['header'=>$html, 'body'=>$html, 'footer'=>$html]
Table::render(array $headers, array $rows)
Heading::render(string $text, int $level)
Alert::render(string $message, string $type)
```

### Latte 宏 (templates/atoms/design-system.latte)

```latte
{include '../atoms/design-system.latte'}
{include #button, text: '提交', variant: 'primary', size: 'md'}
{include #input, name: 'email', type: 'email', placeholder: '...'}
{include #badge, text: 'Active', variant: 'success'}
```

---

## T 层: 数据注入安全标准

```php
// ✅ D 层注入 JSON → T 层 |noescape 输出
// public/page.php
LatteEngine::display('pages/page', [
    'itemsJson' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG),
    'chartDataJson' => json_encode($chartData, JSON_UNESCAPED_UNICODE),
]);
```

```latte
{* ✅ T 层: Latte 变量自动转义，仅 _html/_json 后缀用 |noescape *}
{=$preRenderedHtml|noescape}        ← PHP 组件 HTML (已转义)
{=$itemsJson|noescape}               ← JSON (已编码)
<script>window.__DATA = {$chartDataJson|noescape};</script>  ← Stimulus 数据源
```

```php
// ❌ 禁止: 模板里裸写 json_encode 或 SQL
// {var $json = json_encode($data)}  ← 不要在 T 层做 D 层的事
```

---

## A 层: Stimulus Controller 模板 (三态完备)

```js
// public/build/js/controllers/xxx_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["list", "empty", "error"];
    static values = { loading: Boolean, error: String };

    connect() {
        this.loadingValue = false;
        this._render();
    }

    disconnect() {
        // 清理定时器、事件监听器
    }

    // data-action="click->xxx#refresh"
    refresh() {
        this.loadingValue = true;
        this._render();
    }

    // ═══ 三态渲染 ═══
    get isEmpty() { return !this.loadingValue && !this.errorValue && !this.hasListTarget; }
    get isError() { return !!this.errorValue; }
    get isLoading() { return this.loadingValue; }

    _render() {
        this.listTargets.forEach(t => t.style.display = this.isLoading ? 'none' : '');
        if (this.hasEmptyTarget) this.emptyTarget.style.display = this.isEmpty ? '' : 'none';
        if (this.hasErrorTarget) this.errorTarget.style.display = this.isError ? '' : 'none';
    }
}
```

---

## T 层: Latte 语法安全

```latte
{* ✅ Stimulus 使用标准 HTML 属性，与 Latte {} 零冲突 *}
<div data-controller="dropdown" data-action="click->dropdown#toggle">

{* ✅ CSS/JS 块用 {syntax off} 包裹 *}
{syntax off}<style>.card { color: var(--content-primary); }</style>{/syntax}
{syntax off}<script>window.__DATA = {$dataJson|noescape};</script>{/syntax}

{* ❌ 禁止: 模板内写 {php} 块执行逻辑 *}
{* ✅ 正确: PHP 控制器预计算 → 传变量 *}
```

> Latte 语法安全详解见 `.claude/rules/07-latte-best-practices.md`

---

## 模板文件组织

```
templates/
├── _layout.latte          ← Stimulus Dock 布局 (VS Code 风格)
├── index.latte            ← Stimulus SPA 路由入口 (自包含 HTML)
├── _layouts/main.latte    ← Stimulus 传统布局 (备选)
├── _content/              ← 无 {layout} 的内容片段 (SPA 路由复用)
│   ├── dashboard-body.latte
│   ├── campaigns-body.latte
│   └── ...
├── pages/                 ← 带 {layout} 的完整页面 (PHP 直调)
│   ├── dashboard.latte    → {include '_content/dashboard-body.latte'}
│   ├── campaigns.latte    → {include '_content/campaigns-body.latte'}
│   └── ...
├── atoms/                 ← L1 原子 Latte 宏 (design-system.latte)
├── molecules/             ← L2 分子 Latte 模板
└── _partials/             ← 跨页面共享片段 (dock-panels, toolbar-extras)
```

**规则**: 每个 UI 功能同时有 `pages/xxx.latte` (带 layout, PHP 直调) 和 `_content/xxx-body.latte` (无 layout, SPA 路由复用)。通过 `{include}` 共享内容，不重复代码。

---

## 调试指南 (Latte + Stimulus)

| 现象 | 检查顺序 |
|------|---------|
| 页面显示 `{$var}` 原始字符串 | Latte 语法错误 → 检查 `{` 和 `$` 配对 |
| 页面白屏 / 500 | `php -l templates/pages/{page}.latte` 检查编译 |
| Stimulus 交互无反应 | Browser Console → `Stimulus.Application.start()` 是否调用 |
| data-action 不触发 | 检查 action 格式: `event->controller#method` (无空格) |
| Target 找不到 | 检查 `static targets = ["xxx"]` 声明 + `data-x-target="xxx"` |
| ECharts 图表不显示 | 检查 `window.__DATA` 是否在 `<script>` 中注入 |
| 搜索输入卡顿 | 用 `data-action="input->search#search"` (Stimulus) |
| Modal 不弹 | 检查 `data-toggle-target="panel"` + `data-action="click->toggle#toggle"` |
| 暗色主题不切换 | `data-theme-toggle` 按钮 + `theme-toggle.js` (IIFE) |

---

## TDA 禁止模式速查

| ❌ | ✅ | 层 |
|----|----|:---:|
| 模板里写 SQL 查询 | PHP 控制器查询 → 传变量到模板 | T |
| 模板里写复杂业务逻辑 | 提取为 UseCase → D 层调用 | T |
| Stimulus 里 `fetch()` 直调 API | D 层注入 `window.__DATA` → A 层读取 | A |
| 裸 `echo '<div>'` 输出 HTML | 组件 `::render()` + Latte | D |
| 内联 `style="color:#xxx"` | `var(--color-*)` 令牌 | D |
| 模板内 `{php}` 块 | PHP 控制器预计算 → 传变量 | T |
| 跨 Controller 直接调方法 | DOM `dispatchEvent(new CustomEvent(...))` | A |
| 一个文件做两件事 | 拆分到两个文件 | 全部 |
