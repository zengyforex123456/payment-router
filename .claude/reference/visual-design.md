# 原子设计实战 — 好看 + 好用

> 好看 ≠ 花哨。好看 = 可预测的视觉节奏 + 克制的色彩 + 清晰的层级。
> 好用 ≠ 功能多。好用 = 每个状态都被设计过 + 零布局抖动 + 用户始终知道下一步做什么。

## 一、好看 — 视觉四定律

### 定律 1: 8px 网格 — 消灭"随便给个间距"

所有间距值必须是 4px 的倍数（主要用 8px 步进）。

```
--space-1: 4px    紧贴间距 (图标与文字)
--space-2: 8px    小间距 (按钮内边距)
--space-3: 12px   紧凑内边距 (卡片内部)
--space-4: 16px   标准内边距 (卡片 padding)
--space-6: 24px   段落间距 (内容区间距)
--space-8: 32px   大间距 (区块分隔)
--space-12: 48px  超大间距 (页面段落)
--space-16: 64px  页面级间距 (Hero 区)
```

```latte
{* ❌ 任意值 — 视觉节奏混乱 *}
<div style="padding:13px;margin-top:7px;gap:11px">

{* ✅ 8px 网格 — 所有间距自动和谐 *}
<div style="padding:var(--space-4);margin-top:var(--space-2);gap:var(--space-3)">
```

### 定律 2: 模块化字体 — 不超过 4 个字号

```
--text-xs:   0.75rem (12px)  说明文字、标签、时间戳
--text-sm:   0.875rem (14px) 辅助文字、表格内容、按钮
--text-base: 1rem (16px)     正文 (最小可读尺寸)
--text-lg:   1.125rem (20px) 小标题
--text-2xl:  1.5rem (24px)   区块标题
--text-3xl:  1.875rem (32px) 页面标题
```

**铁律**: 任何页面只用其中 **3-4 个** 字号。

### 定律 3: 60/30/10 配色 — 一个页面只需要三种角色

```
60% 中性色 (--surface-* / --content-secondary)  ← 背景+次要文字
30% 主色 (--content-primary)                      ← 正文+标题
10% 强调色 (--accent)                             ← 按钮+链接+高亮
```

```latte
{* ✅ 60/30/10 — 视觉焦点明确 *}
<div class="card" style="background:var(--surface-raised)">    ← 60% 中性
  <h2 style="color:var(--content-primary)">标题</h2>          ← 30% 主色
  <p style="color:var(--content-secondary)">描述</p>           ← 60% 中性
  <button class="btn btn-accent">行动</button>                 ← 10% 强调
</div>
```

### 定律 4: 视觉层级 — 扫读路径明确

用户扫读页面的顺序: **大小 → 颜色 → 位置 → 间距**。

```
扫读优先级 (0.2 秒内):
  1. 页面标题 (--text-3xl + --content-primary)     ← 最大 + 最深
  2. KPI 数值 (--text-2xl + tabular-nums)           ← 第二大 + 等宽数字
  3. 操作按钮 (--accent 背景)                       ← 最醒目的颜色
  4. 辅助文字 (--text-xs + --content-tertiary)     ← 最小 + 最浅
```

## 二、好用 — 每个组件覆盖五态

每个异步组件必须有且只有五种状态之一:

```
idle → loading → ┬→ data (成功渲染)
                 ├→ empty (空数据)
                 └→ error (请求失败)
```

### Stimulus 实现模板

```js
// 每个生物体 Controller 必须声明五态
export default class extends Controller {
    static targets = ["loading", "empty", "error", "content"];
    static values = { state: String };

    connect() { this.stateValue = "idle"; this._render(); }

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
        this.emptyTarget.style.display    = s === "empty" ? "" : "none";
        this.errorTarget.style.display    = s === "error" ? "" : "none";
        this.contentTarget.style.display  = s === "data" ? "" : "none";
    }
}
```

### Latte 五态模板

```latte
<div data-controller="campaigns">
  {* Loading — 骨架屏，不是空白 *}
  <div data-campaigns-target="loading" class="skeleton">
    <div class="skeleton-row"></div>
  </div>

  {* Empty — 最佳的教学时刻 *}
  <div data-campaigns-target="empty" style="display:none">
    {include 'molecules/empty-state.latte',
      icon: '📋', title: '还没有数据',
      desc: '创建第一个条目，开始追踪',
      ctaUrl: '?action=create', ctaText: '新建'}
  </div>

  {* Error — 用户语言，不暴露堆栈 *}
  <div data-campaigns-target="error" style="display:none">
    <p>无法加载数据</p>
    <button data-action="click->campaigns#load">重试</button>
  </div>

  {* Data *}
  <div data-campaigns-target="content" style="display:none">
    <table>...</table>
  </div>
</div>
```

### 五态审查清单

```
□ Loading:  骨架屏 (不是空白), 占位高度 = 真实内容高度 (CLS=0)
□ Empty:    图标 + 标题 + 描述 + 主要 CTA 按钮 (不是空白页)
□ Error:    原因 (用户语言) + 解决方案 (重试按钮/联系支持) + 不暴露堆栈
□ Data:     正常渲染, 与 Loading 状态尺寸一致 (无布局抖动)
□ Idle:     初始状态, 未触发任何数据加载
```

## 三、好看 × 好用的交汇

```
好看 = L0 令牌约束             好用 = L3 生物体五态覆盖
     + L1 原子样式全局同步            + L2 分子可访问性内置
     + 8px 网格视觉节奏              + CLS=0 骨架屏占位
     + 60/30/10 焦点明确             + 响应式 3 断点
     + 模块化字体 3-4 级              + 键盘导航 0 死胡同
```

## 四、响应式 3 断点

```
移动端  < 640px:   单列 · 堆叠 · 简化 · TabBar 底部导航
平板    640-1024px: 双列 · KPI 2列 · 中等间距
桌面    > 1024px:   多列 · 侧边栏展开 · 完整布局
```

```css
--container-max: 1280px;
--content-padding: var(--space-6);   /* 桌面 24px */

@media (max-width: 640px) {
    --content-padding: var(--space-4);  /* 移动端 16px */
}
```

## 五、可访问性 — 内置属性

```
□ 交互元素 ≥ 44×44px (手指点击最小区域)
□ 对比度 ≥ 4.5:1 (正文) / ≥ 3:1 (大文本 ≥18px)
□ :focus-visible 轮廓 (键盘导航可见)
□ <label for="..."> 关联 (屏幕阅读器)
□ alt / aria-label (图片和图标)
□ prefers-reduced-motion (尊重用户系统设置)
```

> 详细令牌值见 `.claude/reference/design-tokens.md`
