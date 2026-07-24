# Content-OS 设计令牌完整参考

> UI 开发前查此表。CLAUDE.md 只列变量名，完整值看这里。
> 来源: `../converge-ui/tokens/design-tokens.json`

## 颜色

```css
/* 品牌色 */
--color-primary: #1E3A5F;
--color-accent: #7C3AED;

/* 语义色 */
--color-success: #22C55E;
--color-warning: #EAB308;
--color-error: #EF4444;

/* 表面 */
--surface-base: #F8FAFC;
--surface-card: #FFFFFF;
--surface-raised: #FFFFFF;

/* 内容 */
--content-primary: #1E293B;
--content-secondary: #64748B;
--content-tertiary: #94A3B8;
--content-inverse: #FFFFFF;

/* 边框 */
--border-default: #E2E8F0;
--border-hover: #CBD5E1;
```

## 间距 (8px 网格)

| Token | 值 |
|------|------|
| `--space-xs` | 4px |
| `--space-sm` | 8px |
| `--space-md` | 16px |
| `--space-lg` | 24px |
| `--space-xl` | 32px |
| `--space-2xl` | 48px |

## 圆角

| Token | 值 |
|------|------|
| `--radius-sm` | 4px |
| `--radius-md` | 8px |
| `--radius-lg` | 12px |
| `--radius-full` | 9999px |

## 字体

| Token | 值 |
|------|------|
| `--font-family` | Inter, system-ui, sans-serif |

## 断点

| Token | 值 |
|------|------|
| mobile | 375px |
| tablet | 768px |
| desktop | 1440px |

## converge-ui 可用组件

| 组件 | 文件路径 |
|------|------|
| PageShell 布局 | `../converge-ui/views/layout/_shell.php` |
| Dock 侧边栏 | `../converge-ui/views/layout/_dock-sidebar.php` |
| 命令面板 | `../converge-ui/views/layout/_cmd-palette.php` |
| 日期切换器 | `../converge-ui/views/_date-switcher.php` |
| Toast 通知 CSS | `../converge-ui/css/toast.css` |
| PageGuardian JS | `../converge-ui/js/page-guardian.js` |
| Skeleton CSS | `../converge-ui/css/skeleton.css` |

## UI 禁止事项

| ❌ | ✅ |
|----|----|
| `style="color: #3b82f6"` | `var(--color-primary)` |
| `width: 300px` | `max-width: 100%` |
| 内联 `<script>` | `js/` 独立文件 |
| 组件内 `fetch()` | 通过 `api.js` |
| `!important` | 合理优先级 |
