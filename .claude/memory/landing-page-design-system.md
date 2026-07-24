---
name: landing-page-design-system
description: 着陆页设计系统 — Tailwind+令牌+12列网格+Dark Mode全链路实战经验
metadata: 
  node_type: memory
  type: project
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# 着陆页设计系统 — 从内联CSS到三级令牌的演进

## 架构

```
v1 (内联CSS 80行) → v2 (Tailwind工具类) → v3 (三级令牌语义化) → v4 (Dark Mode+12列网格+P0-P2动效)
```

## 三级令牌互补架构

**核心原则**: Tailwind处理布局/间距/响应式，设计令牌处理颜色语义。

```
L0 Primitives: gray-50..950·navy-50..900·electric-50..700 (Tailwind config)
L1 Semantic:   surface·content·border·accent (CSS :root变量)
L2 Functional: success·danger·warning
```

**为什么选择互补而非替代**:
- Tailwind `p-6 grid-cols-12` 处理布局 → 零学习成本
- `bg-surface-base text-content-primary` 处理语义 → 灰度先行自动落地
- CSS `rgb(var(--token) / <alpha-value>)` 实现 Dark Mode → 零HTML改动

## 灰度先行方法论

**Why**: 人眼对亮度敏感度是色相的10倍。灰度建立结构，颜色只提供情绪。

**How**: 
1. 用 `content-primary/secondary/tertiary` 三色文字系统 → 所有文字语义化
2. 用 `surface-base/raised/overlay` 三层表面系统 → 所有背景语义化
3. 品牌色只通过 `accent` 令牌 → 仅CTA+章节标签+重点卡片

**Why**: 改一个令牌值，整站色彩关系自动更新。这是设计系统的标志。

## 暗色模式实现

**关键技术**: CSS自定义属性 + Tailwind `<alpha-value>` 模式

```css
:root { --s-base: 248 250 252; }           /* light */
html.dark { --s-base: 15 23 42; }          /* dark */
```

```js
// Tailwind config
surface: { base: 'rgb(var(--s-base) / <alpha-value>)' }
```

**Why**: 所有 `bg-surface-base` 类自动切换，opacity修饰符(`/50`)也正常工作。

**Why**: 
- `prefers-color-scheme: dark` 媒体查询 → 首次访问自动跟随系统
- `localStorage` → 手动切换持久化
- 切换按钮: 亮色显示☀️，暗色显示🌙

## P0-P2 设计优化清单

| 优先级 | 改动 | 效果 | 实现 |
|:--:|------|------|------|
| P0 | 卡片hover lift | 所有卡片hover上浮+阴影扩散 | `hover:shadow-lg hover:-translate-y-1 transition-all duration-300` |
| P0 | 排版断层修复 | 卡片标题text-lg→text-xl | 18px→20px, 填充18-30px断层 |
| P1 | CTA呼吸动画 | Hero主按钮3s脉冲 | `@keyframes cta-pulse` box-shadow动画 |
| P1 | 段间距差异化 | Hero py-24·Final CTA p-12 | 节拍有变化，不单调 |
| P2 | scroll-reveal | 每段进入视口时淡入+上滑 | `IntersectionObserver` + `.reveal` CSS类 |

## 12列统一网格

**结构**: `<main class="grid grid-cols-12 gap-x-6 max-w-6xl">` → 所有section `col-span-full`

**子网格**: 所有内部grid也使用 `grid-cols-12`，子元素通过 `col-span-*` 对齐父网格。

**Why**: 全页左右边距、卡片间距、标题起始位全部对齐同一网格线。

## 提交记录

- `refactor(landing): Tailwind CSS 12列网格重排`
- `refactor(landing): Tailwind + 三级设计令牌系统`
- `feat(landing): dark mode — CSS自定义属性`
- `feat(landing): P0-P2 设计优化 — 卡片hover·排版·CTA脉冲·滚动揭示`

## 复用到新项目

1. 复制 `landing.php` 的 `<script>tailwind.config` 块
2. 复制 `<style>` 中的 `:root` + `html.dark` 令牌块
3. 用语义类: `bg-surface-base` / `text-content-primary` / `border`
4. 灰度验证: `filter: grayscale(1)` 下层級仍清晰
5. 加交互: `hover:shadow-lg hover:-translate-y-1 transition-all duration-300`
6. 加动效: `IntersectionObserver` + `.reveal` 类
