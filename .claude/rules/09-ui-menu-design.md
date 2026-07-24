# Converge 菜单设计规则

> 层: L3 领域规格 | 适用: Converge 项目 | 继承: `10-ui-menu-naming.md` (全局)
> 数据来源: 2025-2026 SaaS UX 最佳实践 + ALF Design Group + Equal.design

## 架构规则

- 菜单项必须通过 `Hooks::addFilter('ui.dock.panels')` 由各模块在 `bootstrap.php` 中自注册
- 禁止在 Controller 或 View 中硬编码菜单 HTML
- 菜单数据必须是 PHP 数组，由 `_dock-sidebar.php` 统一渲染

## 菜单项结构

每个菜单项必须包含:
```php
['id' => 'module-name', 'label' => '动词+宾语', 'icon' => '📝', 'order' => 10]
```

| 字段 | 类型 | 规则 |
|------|------|------|
| `id` | string | 小写+连字符, 匹配模块名 |
| `label` | string | 动词+宾语, ≤6 字 |
| `icon` | string | 单 emoji, 图标+文字配对显示 |
| `order` | int | 10/20/30... 留间隔便于插入 |

## 认知负荷 (来源: ALF Design Group)

- **一级菜单 5-7 项** — 超过 7 个说明信息架构有问题, 不是设计问题
- **三级层次**:
  - L1 主导航: 15-16px 粗体, 清晰激活态 — 最多 7 项
  - L2 次级: 13-14px, 缩进 16-24px, 仅在父级展开时可见
  - L3 工具区: 小号灰色, 设置/帮助/账号, 底部分隔线
- **三键法则**: Top 3 功能一键可达, 任何功能不超过 3 次点击
- **无裸名词**: Runner → 管理 Runner; IAM → 管理权限
- **每个菜单项是"负债"** — 存在必须被点击证明价值

## 侧边栏尺寸 (来源: ALF Design Group + Singapore DS)

| 属性 | 值 | 令牌 |
|------|------|------|
| 展开宽度 | 220-260px | `--sidebar-width` |
| 收起宽度 | 56-72px (仅图标) | `--sidebar-collapsed` |
| 菜单项高度 | 40-48px | `--nav-item-height` |
| 内边距 | 16px | `--space-md` |
| 网格 | 8px 基准 | — |

## 交互状态 (来源: WCAG 2.1 AA)

- **激活态**: 填充色 + 左边框强调线 (`var(--accent-emphasis)`)
- **悬停态**: 5-10% 透明度变化
- **聚焦态**: 可见的 focus ring (键盘导航必需)
- **收起态**: 仅图标 + hover tooltip
- **动画**: 150ms 快速 / 200-300ms 标准 (collapsed ↔ expanded)

## 动态响应

- 结合 `FeatureRegistry` 为不同套餐显示/隐藏菜单
- 结合 `Permission::can()` 为不同角色控制可见性
- 试用用户: 显示升级提示而非隐藏高级功能
- **角色驱动**: 不同角色看不同菜单, 不建多套模板

## 审查清单

- [ ] 一级菜单 ≤7 项?
- [ ] 菜单项通过 Hooks 注册?
- [ ] 包含 `id`, `label`, `icon`, `order`?
- [ ] label "动词+宾语"且 ≤6 字?
- [ ] 只用 CSS 变量, 无硬编码颜色?
- [ ] 激活态有左边框强调线?
- [ ] 有 focus ring (键盘导航)?
- [ ] 响应式: 窄屏收起为图标模式?
