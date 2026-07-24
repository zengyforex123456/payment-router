# TDA 三层架构 + 原子设计五层模型

> Converge 项目的 UI 架构规范定义。写任何 UI 代码前必读。
> 配套: `02-ui-architecture.md` (Agent 执行流程) · `visual-design.md` (好看+好用设计实践)

## TDA 三层架构

```
┌─ T: Latte 模板层 ─────────────────────────────────────────────┐
│  职责: 纯 HTML 骨架，声明式 Stimulus 绑定                        │
│  语法: {layout} {include} {block} {=expr} {$var}              │
│  不做: SQL 查询 · fetch() · 业务逻辑 · 状态管理                  │
│  标记: data-controller="x" data-x-target="y"                  │
│        data-action="event->controller#method"                 │
└────────────────┬──────────────────────────────────────────────┘
                 │ {$vars} + {=$html|noescape}
┌────────────────▼──────────────────────────────────────────────┐
│  D: PHP 数据层                                                 │
│  职责: 数据查询 · 组件 HTML 生成 · JSON 安全注入                 │
│  文件: public/{page}.php → LatteEngine::display()             │
│  不做: 裸 echo HTML · 内联 style · 模板内写 SQL                 │
│  组件: Badge::render() StatCard::render() Grid::render()      │
└────────────────┬──────────────────────────────────────────────┘
                 │ data-* 属性 + window.__DATA
┌────────────────▼──────────────────────────────────────────────┐
│  A: Stimulus 行为层                                             │
│  职责: 交互响应 · DOM 操作 · 事件处理 · localStorage             │
│  文件: public/build/js/controllers/{name}_controller.js       │
│  不做: fetch() 直调 API · 跨 Controller 直接调用                 │
│  约定: Controller + Target + Value + Action                   │
└──────────────────────────────────────────────────────────────┘
```

### 架构四原则

#### 1. 分层 (Layering) — T → D → A 单向

| 方向 | 机制 | 禁止 |
|------|------|------|
| D → T | `LatteEngine::display()` 传变量 | T 调 PHP 函数 |
| T → A | `data-controller` HTML 属性声明 | A 操作 T 模板文件 |
| A → D | `<form>` 提交 / `<a href>` 跳转 | A 直调 PHP |
| A ↔ A | DOM 事件 `dispatchEvent()` | 跨 Controller 直调方法 |
| D → A | `window.__DATA` / `window.__API` JSON 注入 | A 读 PHP session |

#### 2. 分模块 — 每个文件一个独立模块

| 层 | 模块粒度 | 零共享状态 |
|------|------|------|
| T | 一个 .latte = 一个页面或可复用片段 | 通过 {include} 传参数 |
| D | 一个 public/*.php = 一个页面控制器 | 通过 LatteEngine 传变量 |
| A | 一个 _controller.js = 一个交互域 | 通过 DOM 事件通信 |

#### 3. 单一功能 — 一个文件只有一个变更理由

| 拆分信号 | 行动 |
|------|------|
| 文件 >150 行 | 提取子文件 |
| 函数描述含"和"字 | 拆分为两个函数 |
| Controller >80 行 | 拆分为多个 Controller |
| PHP 页面控制器 >100 行 | 提取 UseCase |

#### 4. 接口通信 — 层间只通过标准化接口

| 接口 | 方向 | 格式 |
|------|------|------|
| `LatteEngine::display(page, vars)` | D → T | `['var' => $value, 'html' => $html]` |
| `data-controller="x"` | T → A | HTML 属性 |
| `data-x-target="y"` | T → A | Stimulus Target |
| `data-action="e->ctrl#m"` | T → A | Stimulus Action |
| `window.__DATA` / `window.__API` | D → A | JSON (PHP `json_encode`) |
| `<form method="post">` | A → D | HTTP POST |
| `<a href="?page=x">` | A → D | HTTP GET |
| `this.dispatchEvent(new CustomEvent(...))` | A ↔ A | DOM 事件冒泡 |

---

## 原子设计五层模型 (Atomic Design)

> Brad Frost 方法论 — 界面 = 化学物质，由不可再分的"原子"逐级组合成"页面"

```
L0 设计令牌 (Design Tokens)  ← 基因编码
    │  颜色/间距/圆角/字体/动画   — 唯一真相源，代码强制约束品牌一致性
    │
L1 原子 (Atoms)              ← 基本粒子，不可再分
    │  <button> <input> <label> <h1> <span> <svg>
    │  零业务逻辑 · 零 Stimulus Controller · 只有 class + data-action
    │
L2 分子 (Molecules)          ← 原子组合，单一功能
    │  搜索框 (Input+Button) · 表单标签组 (Label+Input+Error)
    │  可有简单 Stimulus 绑定 · 不可引入独立 data-controller
    │
L3 生物体 (Organisms)        ← 分子/原子聚合，独立 UI 区块
    │  导航栏 · 数据表格 · 侧边栏 · 模态框
    │  可引入独立 data-controller · 协调内部多个分子的状态
    │
L4 模板 (Templates)          ← 生物体骨架，只关注布局和占位
    │  左右布局 · 上下布局 · 弹窗模板 — 不填充具体数据
    │
L5 页面 (Pages)              ← 完整生物体，填充真实数据的最终渲染
    │  admin-panel.php → LatteEngine::display() → 完整 HTML
```

### L0: 设计令牌 — 基因编码

品牌一致性靠代码强制约束，不靠设计师手动吸色。

| 类别 | 令牌 | 禁止 |
|------|------|------|
| 颜色 | `var(--color-*)` `var(--surface-*)` `var(--content-*)` | `#xxx` `rgb()` |
| 间距 | `var(--space-xs)` ~ `var(--space-2xl)` (8px 网格) | `16px` `1rem` |
| 圆角 | `var(--radius-sm)` ~ `var(--radius-full)` | `4px` |
| 字体 | `var(--font-family)` | `Arial` |
| 动画 | `var(--motion-fast)` (150ms) / `var(--motion-normal)` (300ms) | 无动画或 >500ms |

**令牌级联铁律 (P0, 提交阻断)**:
- `design-tokens.css` 是**唯一允许定义 `:root {}` 令牌的文件**
- 其他 CSS 禁止在 `:root` 中硬编码任何令牌值
- 允许 `var()` 桥接: `--my-color: var(--content-primary);` ✅
- 门禁: G7 `check-token-source.php` — `:root` 竞争 → 阻断提交

> 详细令牌值见 `.claude/reference/design-tokens.md`

### L1: 原子 — 零逻辑，纯渲染

不可再分的基础 HTML 元素。**原子绝对不包含业务逻辑**。

| 组件 | 文件 | 签名 |
|------|------|------|
| Badge | `app/UI/Badge.php` | `::render(text, variant)` — `success|warning|danger|info|default` |
| Button | `app/UI/Button.php` | `::render(text, variant, ?href)` — `primary|ghost|danger` |
| Input | `app/UI/Input.php` | `::render(name, type, placeholder)` |
| Spinner | `app/UI/Spinner.php` | `::render()` |
| Icon | Latte macro | `{include '../atoms/icon.latte', name: 'search'}` |

```latte
{* ✅ 原子: 零逻辑，只声明行为绑定 *}
<button class="btn btn-primary" data-action="click->organismCtrl#method">

{* ❌ 禁止: 原子引入 data-controller *}
<button data-controller="something">  ← 原子不配拥有独立控制器
```

### L2: 分子 — 单一功能，可组合

由多个原子组合，具备**唯一的明确功能**。不可引入独立 `data-controller`。

| 组件 | 组成 | 签名 |
|------|------|------|
| StatCard | Badge + Heading + Text | `::render(['icon','label','value','change','trend'])` |
| MetricCard | Icon + Data + Label | `::render(['value','label','trend'])` |
| EmptyState | Icon + Heading + Text + Button | `::render(icon, title, desc, ?ctaUrl, ?ctaText)` |
| PageHeader | Heading + Text + Actions | `::render(title, subtitle, actionsHtml)` |
| DataTable | Table + 排序/筛选/分页 分子 | `::render(headers, rows)` |
| FormGroup | Label + Input + Error提示 | `{include 'molecules/form-group.latte'}` |

### L3: 生物体 — 独立区块，可自治

**这是唯一可以引入独立 `data-controller` 的层级**。

| 组件 | 组成 | 文件 |
|------|------|------|
| 侧边栏 (Sidebar) | Logo分子 + 菜单分子 + 用户头像分子 | `templates/organisms/sidebar.latte` |
| 导航栏 (Navbar) | 搜索分子 + 时钟原子 + 下拉分子 | `templates/_partials/dock-panels.latte` |
| 模态框 (Modal) | 遮罩原子 + 卡片分子 + 表单分子 | 由 `modal_controller.js` 驱动 |
| 仪表盘 (Dashboard) | KPI分子组 + 图表生物体 + AI洞察分子 | `templates/_content/dashboard-body.latte` |
| 活动管理 (Campaigns) | 表格生物体 + 搜索分子 + 模态生物体 | `templates/_content/campaigns-body.latte` |

### L4: 模板 — 布局骨架，不含数据

只关注布局和占位，不填充具体数据。

```
templates/
├── _layout.latte          ← 全站布局模板 (Dock + Content)
├── _layouts/
│   ├── main.latte         ← 传统侧边栏布局模板
│   ├── public.latte       ← 公开页布局模板
│   └── list-page.latte    ← 列表页通用模板
├── _content/              ← 无 {layout} 的内容片段 (SPA 路由复用)
└── pages/                 ← 完整页面 (L5)
```

### L5: 页面 — 填入真实数据

```
public/{page}.php                          ← D 层: 查询数据 + 注入变量
    │  LatteEngine::display('pages/page', [
    │      'campaigns' => $rows,
    │      'stats'     => $stats,
    │  ])
    ▼
templates/pages/{page}.latte               ← T 层: {layout} + {include} + {block}
    ▼
完整 HTML → 浏览器渲染
```

### 原子设计审查清单

```
□ L0: 无硬编码颜色/间距/圆角? (只使用 var(--xxx) 令牌)
□ L1: 原子无 data-controller? (只有 data-action)
□ L2: 分子 ≤1 个明确功能? (描述无"和"字)
□ L3: 生物体的 Controller 只协调内部状态? (不跨生物体直接调方法)
□ L4: 模板无具体数据? (只有 {block} 占位)
□ L5: 页面无裸 HTML? (全通过 {include} 组装)
□ PHP 只传数据 → 模板只做渲染?
```

---

## Stimulus 分层绑定规则

| 层级 | data-controller | data-action | data-target |
|------|:---:|:---:|:---:|
| L1 原子 | ❌ 禁止 | ✅ 允许 (指向生物体方法) | ❌ 禁止 |
| L2 分子 | ❌ 禁止 | ✅ 允许 | ❌ 禁止 |
| L3 生物体 | ✅ **仅此层可** | ✅ 允许 | ✅ 允许 |
| L4 模板 | ❌ 禁止 | ❌ 禁止 | ❌ 禁止 |
| L5 页面 | ❌ 禁止 | ❌ 禁止 | ❌ 禁止 |

---

## 组件命名规范

| 层级 | 命名格式 | 示例 |
|------|------|------|
| L1 原子 | `{element}.latte` 或 PHP `{Name}::render()` | `button.latte`, `Badge::render()` |
| L2 分子 | `{domain}-{function}.latte` | `form-group.latte`, `search-group.latte` |
| L3 生物体 | `{domain}.latte` + 控制器 `{domain}_controller.js` | `sidebar.latte`, `campaigns_controller.js` |
| L4 模板 | `{layout-type}.latte` | `main.latte`, `public.latte` |
| L5 页面 | `{page-name}.latte` → `{page-name}.php` | `dashboard.latte`, `admin-panel.php` |

---

## 文件物理层映射

```
templates/
├── atoms/                 ← L1: Latte 宏
│   ├── design-system.latte
│   └── icon.latte
├── molecules/             ← L2: 原子组合
│   ├── form-group.latte
│   ├── stat-card.latte
│   ├── data-table.latte
│   └── empty-state.latte
├── organisms/             ← L3: 独立区块 + 可选 _controller.js
│   ├── sidebar.latte
│   └── modal.latte
├── _layouts/              ← L4: 布局骨架
│   ├── main.latte
│   └── public.latte
├── _content/              ← 无壳内容片段 (SPA 路由复用)
└── pages/                 ← L5: 完整页面
```

### 门禁: G14 enforce-ui-architecture

```bash
php bin/tool run enforce-ui-architecture           # 全量检查
php bin/tool run enforce-ui-architecture --staged  # 仅 staged
```

规则:
1. `public/*.php` 页面必须通过 `LatteEngine::display()` 渲染
2. 禁止 `echo '<' . 'div'` 等裸 HTML 标签输出
3. 禁止 `style="..."` 含 `px/rem/em/#xxx/rgb()` 硬编码值
4. 豁免: `api-*.php` (JSON), `_layout*.php` (布局), `builder.php` (复杂工具)

---

## TDA 三支柱评估 — 可观察·可追溯·可验证

### 🔭 可观察 (Observability)

Stimulus 声明式绑定让控制流在 HTML 中可见：

```
旧 (Alpine):  <div x-data="{open:false}" @click="open=!open" x-show="open">
              → 行为、状态、渲染混在一行 HTML

新 (TDA/Stimulus):
  <div data-controller="dropdown">                   ← 谁控制
    <button data-action="click->dropdown#toggle">    ← 什么触发
    <div data-dropdown-target="panel">               ← 影响谁
  → 三个独立属性，各自明确
```

### 📋 可追溯 (Traceability)

```
用户看到「仪表盘 KPI 值 1,234」
  ↓ 追溯路径
  T 层: templates/_content/dashboard-body.latte  ← {=number_format(...)} 
  D 层: public/admin-panel.php                   ← SQL 查询 → 传 $kpis
  A 层: 无 (纯 PHP 格式化)                         ← 静态渲染，零 JS
```

### ✅ 可验证 (Verifiability)

```
L1 语法验证:
  php -l templates/_layout.latte        ✅ T 层零语法错误
  node --check bundle.min.js            ✅ A 层零语法错误

L2 单元测试:
  dropdown_controller.js                ✅ ES6 class，可独立单测

L3 契约验证:
  data-controller="dropdown" 注册了吗?   ✅ stimulus-app.js 集中注册

L4 集成验证:
  Controller.connect() → 状态初始化      ✅ 检查 localStorage + CSS class
```
