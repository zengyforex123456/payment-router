# 编码实现规范 — 硬性指标·UI/UX·PRD追溯

> 层: L2 工程规范 | 版本: v1.0 | 替换: 01-implement.md 编码+UI/UX段

## Trigger

P2 编码阶段（由 `01-sdlc-lifecycle.md` 触发）/ "实现" "开发" "编码" 关键字

## Input

- PRD 需求清单（来自 P1 输出）
- 架构决策（来自 `00-architecture-meta.md`）
- 前端架构（`09-frontend-architecture.md`，如为前端项目）
- 菜单命名（`10-ui-menu-naming.md`，如涉及 UI）

## Action

### 硬性指标

| 指标 | 限制 |
|------|------|
| 函数体 | ≤ 50 行 |
| 嵌套层级 | ≤ 3 层 |
| 参数数量 | ≤ 4 个，超过用对象 |
| 单一职责 | 函数描述含 "和" 字 → 拆分 |

### UI/UX 强制规范

**必须遵循**：
- 现代组件库：shadcn/ui、Radix、Ant Design 6+（禁止 Bootstrap 3/4、jQuery UI）
- CSS 组织：CSS Modules / Tailwind / Styled-components
- 响应式：移动优先，相对单位（%、rem、em）
- 主题：CSS Variables 定义颜色/间距

**禁止**：

| ❌ | ✅ |
|----|----|
| 内联 `style={{}}` | CSS Modules / Tailwind |
| 固定像素 `width:300px` | `max-width:100%` |
| `!important` | 合理优先级 |
| 硬编码 `#3b82f6` | `var(--color-primary)` |
| jQuery DOM 操作 | 框架状态管理 |
| float/table 布局 | Flexbox/Grid |

**可访问性（强制）**：
- [ ] 交互元素 ≥44x44px
- [ ] 对比度 ≥4.5:1
- [ ] 键盘导航可用
- [ ] 表单 `<label>` + `for`
- [ ] 图片 `alt`，图标 `aria-label`
- [ ] 支持 `prefers-reduced-motion`

目标：Lighthouse ≥90，axe-core 0 违规

### 自动流程

```
触发 → 任务拆解(按FE/BE/QA/CN) → 依赖排序 → 分配执行 → 输出代码+测试
```

### PRD 约束

- 每写一个函数必须能回答"对应 PRD 哪条需求"
- 禁止添加 PRD 外功能、跳过需求、顺手重构
- 发现 PRD 遗漏 → 停止编码，回 P1 确认

## Output

- PRD 可追溯的代码文件
- 通过硬性指标检查（≤50行/函数、≤3层嵌套、≤4参数）

## Interface Contract

- **消费者**: `01-sdlc-lifecycle.md`（P2 阶段引用）
- **依赖**: `09-frontend-architecture.md`（前端分层）、`10-ui-menu-naming.md`（菜单命名）、`04-security-standards.md`（安全规则）
- **输出格式**: 代码文件 + PRD 追溯标注
- **约定**: 函数级 SRP 强制；UI 组件库白名单（shadcn/ui、Radix、Ant Design 6+）
