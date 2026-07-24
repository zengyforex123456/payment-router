---
name: dock-layout-css-leakage
description: 旧侧边栏240px margin泄漏到80px dock模式→布局错乱
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# Dock 布局错乱 — 旧 CSS 泄漏到新模式

## 症状

侧边栏菜单出现但布局错乱——二级菜单（dock tabs）不显示为水平 Tab 栏，而是占了中间一列。整体布局不协调。

## 根因链

```
症状: Dock tabs 占中间列，布局难看
  ← 直接原因: .main-content 有 240px 左边距，但 dock sidebar 仅 80px
  ← 深层原因: .dock-sidebar 未 fixed 定位，参与 flex 流，与新 margin 冲突
  ← 根因: 旧 .sidebar (240px, position:fixed) 的 CSS 未被 dock 模式覆盖
```

## 3 个具体 Bug

### Bug 1: `margin-left` 未覆写

```css
/* main.css — 旧侧边栏 */
.main-content { margin-left: var(--sidebar-width); }  /* 240px */

/* dock-layout.css — 未覆写！ */
/* .main-content.main-dock 不存在 → 240px margin 生效 → 160px 空白 */
```

### Bug 2: Dock sidebar 未固定定位

```css
/* dock-layout.css — 旧版 */
.dock-sidebar {
    width: 80px;
    /* 缺: position: fixed; top:0; left:0; bottom:0; */
    /* → 参与 flex 流，被主内容挤占 */
}
```

对比旧侧边栏：
```css
/* main.css — 旧 sidebar 正确做法 */
.sidebar { position: fixed; top:0; left:0; bottom:0; }
```

### Bug 3: Logo CSS 类名不一致

| 位置 | 类名 | 状态 |
|------|------|:--:|
| HTML `_dock-sidebar.php` | `.dock-logo` `.dock-logo-link` `.dock-logo-text` | ✅ |
| CSS `dock-layout.css` (旧) | `.dock-sidebar .sidebar-logo` `.sidebar-brand` | ❌ 不匹配 |

## 修复

```css
/* 1. Fixed positioning + 2. margin override */
.dock-sidebar {
    position: fixed; top: 0; left: 0; bottom: 0;
    z-index: 100;
    width: 80px;
}
.main-content.main-dock { margin-left: 80px; }

/* 3. Correct logo classes */
.dock-logo { ... }
.dock-logo-link { ... }
.dock-logo-text { ... }
```

## 为什么测试没发现

| 检测手段 | 能发现？ | 原因 |
|------|:--:|------|
| php -l | ❌ | CSS 语法永远合法 |
| lint-php-patterns.sh | ❌ | 只检查 PHP |
| lint-css-classes.sh | ⚠️ | 只检查类名**存在性**，不检查**布局正确性** |
| auth-ui-smoke.spec.js | ⚠️ | 检查元素可见性，不检查**视觉布局** |
| **视觉回归测试 (Playwright screenshot)** | ✅ | 截图对比会暴露 160px 空白 |

## 预防升级

在 CSS lint 中增加**布局约束检查**：
1. 检查 `position: fixed` 元素是否设置了对应方向的坐标 (top/left/bottom/right)
2. 检查 `margin-left` 是否与侧边栏宽度一致
3. 视觉回归截图测试 (真正的最后防线)

### 新增工具: `scripts/lint-css-layout.sh`

```bash
# 检查: 侧边栏宽度 vs 主内容 margin 一致性
sidebar_width=$(grep -oP '\.dock-sidebar\s*\{[^}]*width:\s*\K\d+' dock-layout.css)
main_margin=$(grep -oP '\.main-content\.main-dock\s*\{[^}]*margin-left:\s*\K\d+' dock-layout.css)
if [ "$sidebar_width" != "$main_margin" ]; then
    echo "ERROR: sidebar width ($sidebar_width) != main margin ($main_margin)"
fi
```

## 用户视角补充（来自实操验证）

用户提出的 CSS 类名不匹配只是冰山一角——Logo 样式失效是**可见症状**，真正的**布局杀手**是旧 CSS margin 泄漏。

### 排查优先级（实战验证）

```
症状: 界面布局错乱
  │
  ├─ ① 先查布局: margin/padding/position 是否正确
  │     → 旧模式 margin-left:240px 泄漏到新模式
  │
  ├─ ② 再查定位: fixed/sticky 元素是否脱离流
  │     → dock-sidebar 缺 position:fixed
  │
  └─ ③ 最后查样式: CSS 类名是否匹配
        → Logo 类名 .sidebar-logo vs .dock-logo
```

教训：**类名不匹配导致"不好看"，布局泄漏导致"完全不能用"。排查时先修布局再修样式。**

## 关联

- [[sidebar-four-bugs-blind-spot]] — 前 4 个侧边栏 bug 的同类分析
- [[converge-ui-deploy-lessons]] — 部署经验全集
- [[css-root-variable-conflict]] — 类似 CSS 变量冲突问题
