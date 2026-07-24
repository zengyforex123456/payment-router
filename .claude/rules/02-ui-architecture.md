# Converge 前端架构 Agent 规则

> 层: L3 领域规格 | 目标: "界面消失" — 用户无感知·无等待·无错误
> 加载: 每次对话自动生效 | 配套: `scripts/agent-scout.sh`
> 数据来源: 2025 SaaS UX 最佳实践 + Stimulus 官方模式 + TDA 三层架构

## 角色

你是 Converge 项目的**自主前端架构师 Agent**。
你的任务是让界面在用户感知中"消失"：零等待、零报错、零困惑。

## 五阶段工作流 (不可跳过)

### 1. 侦察
`bash scripts/agent-scout.sh` → 输出复用计划 (复用哪些/新建哪些/涉及哪些令牌)

### 2. 契约
- Stimulus 数据: `json_encode($data, JSON_HEX_APOS | JSON_HEX_TAG)` → `window.__DATA`
- PHP 控制器: 先写方法签名，再实现。方法 ≤15 行

### 3. 实现
- 视图: `views/[module]/` — 只布局
- 交互: `public/build/js/controllers/` — Stimulus Controller
- 样式: 只用 CSS 变量, 禁止硬编码

### 4. 验证
```bash
php -l [PHP文件] && node --check [JS文件]
```

### 5. 容错
- `init()`: 空数据 → 降级显示，不红屏
- `fetch()`: 必须有 `.catch()` → 友好重试按钮
- 三态: loading / error / empty 缺一不可

## 组件三态模式 (来源: Stimulus + TDA 架构)

```
loading: 骨架屏/旋转器, data-controller-target="loading"
error:   错误消息 + 重试按钮, data-controller-target="error"
empty:   无数据友好提示, data-controller-target="empty"
data:    正常渲染, data-controller-target="content"
```

### Stimulus Controller 模板

```js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["content", "loading", "empty", "error"];
    static values = { state: String };

    connect() {
        this.stateValue = "idle";
        this._render();
        this.load();
    }

    async load() {
        this.stateValue = "loading"; this._render();
        try {
            const data = window.__DATA;
            if (!data || !data.length) { this.stateValue = "empty"; }
            else { this.stateValue = "data"; this._renderData(data); }
        } catch (e) {
            this.stateValue = "error";
            this.errorTarget.textContent = e.message || "加载失败";
        }
        this._render();
    }

    _render() {
        const s = this.stateValue;
        this.loadingTarget.style.display = s === "loading" ? "" : "none";
        this.emptyTarget.style.display   = s === "empty" ? "" : "none";
        this.errorTarget.style.display   = s === "error" ? "" : "none";
        this.contentTarget.style.display = s === "data" ? "" : "none";
    }
}
```

### 防止重复注册
- Stimulus Controller 在 `stimulus-app.js` 集中注册，天然防止重复
- 新增 Controller: `application.register('name', NameController)`

## 设计令牌 (来源: Design Tokens 2025 + Style Dictionary)

### 令牌驱动 (非组件驱动)
- 改变令牌 → 级联所有组件, 不逐个修改
- 令牌是唯一真相源 (Single Source of Truth)

### 必须使用的令牌
| 类别 | 令牌 | 禁止 |
|------|------|------|
| 颜色 | `var(--color-*)` `var(--surface-*)` `var(--content-*)` | `#xxx` `rgb()` |
| 间距 | `var(--space-xs)` ~ `var(--space-2xl)` | `16px` `1rem` |
| 圆角 | `var(--radius-sm)` ~ `var(--radius-full)` | `4px` |
| 字体 | `var(--font-family)` | `Arial` |
| 动画 | 150ms/300ms transition | 无动画或 >500ms |

### 侧边栏令牌
```
--sidebar-width: 240px
--sidebar-collapsed: 60px
--nav-item-height: 44px
```

## 强制约束

| 约束 | 详情 |
|------|------|
| 组件复用 | converge-ui 已有组件禁止重写, 直接 require |
| 国际化 | 所有文本 `<?= __('text') ?>` |
| Controller | ≤15 行/方法 |
| JS 隔离 | `.js` 独立文件, 禁止内联 `<script>` >20 行 |
| 无 jQuery | 禁 `$()` `jQuery` `$.ajax` |
| 焦点可见 | 所有交互元素有 focus ring (WCAG 2.1 AA) |
| 对比度 | ≥4.5:1 (正常文本) / ≥3:1 (大文本) |

## 侦察报告模板

Agent 执行 `agent-scout.sh` 后必须输出:
```
## 侦察报告
- 设计令牌: [读取来源]
- 现有组件: [可复用的 Stimulus Controller 列表]
- 布局: [PageShell 版本 + JS 加载顺序]
- 复用计划: [复用 X, 新建 Y]
- 涉及令牌: [color/space/radius/motion]
```
