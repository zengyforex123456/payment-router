---
name: sidebar-four-bugs-blind-spot
description: 4个侧边栏bug无一被35个测试发现——根因+预防+检测工具
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# 侧边栏 4 Bug 全漏检 — 测试盲区分析

## 四个 Bug

| # | 文件 | Bug | 类型 |
|:--:|------|-----|------|
| 1 | `_dock-sidebar.php:9` | `empty($FEATURE_DOCK_NAV)` 检查变量非常量 → 恒 true → sidebar 不渲染 | 变量/常量混淆 |
| 2 | `dock-layout.css:5` | CSS `.sidebar-dock` vs HTML `.dock-sidebar` → 样式永不应用 | CSS-HTML 类名不一致 |
| 3 | `_dock-sidebar.php:76` | `$P::allowsPage()` 方法不存在 → 运行时 fatal error | 未定义静态方法 |
| 4 | `_dock-sidebar.php:35` | `new Permission()` 无构造参数 → 运行时 fatal error | 缺少必需参数 |

## 为什么 35 个测试全漏检

```
现有测试覆盖:
  deploy-verify.spec.js (13 tests)  → 100% 公共页面 (landing/login/pricing/register)
  full-chain.spec.js   (22 tests)  → 100% API/数据链路

盲区:
  登录后 UI (侧边栏/Dock/导航/仪表盘) → 0 tests ← 4 个 bug 全在这里
```

**根因**: 测试金字塔倒置 — 公共页测试充足，登录后 UI 零覆盖。

## 为什么 php -l 不报错

| Bug | php -l 结果 | 原因 |
|-----|:--:|------|
| `empty($UNDEFINED_VAR)` | ✅ 通过 | PHP: empty() 对未定义变量合法，返回 true |
| CSS 类名不一致 | ✅ 通过 | CSS 和 PHP 是不同文件，php -l 只看语法 |
| `$P::allowsPage()` | ✅ 通过 | PHP 不检查静态方法在编译时是否存在 |
| `new Permission()` | ✅ 通过 | PHP 不检查构造函数参数在编译时 |

## 预防：3 层防御

| 层 | 工具 | 能捕获 |
|:--:|------|------|
| L1 | `scripts/lint-php-patterns.sh` | Bug 1, 3, 4 — 变量/常量混淆、未定义方法、无参构造 |
| L2 | `scripts/lint-css-classes.sh` | Bug 2 — CSS-HTML 类名交叉引用 |
| L3 | `tests/E2E/auth-ui-smoke.spec.js` | All 4 bugs — 登录后 UI 冒烟 (20 tests, 6 gates) |

## 修复

```php
// Bug 1: 变量 → 常量
- if (empty($FEATURE_DOCK_NAV)) return;
+ if (!defined('FEATURE_DOCK_NAV') || !FEATURE_DOCK_NAV) return;

// Bug 3: 删除不存在的静态方法调用
- $P::allowsPage($tab['page'])
+ // 所有 Tab 显示，单页权限由 index.php 控制

// Bug 4: 删除无参构造
- if (... || (new \Converge\Auth\Permission)->allows(...))
+ if (\Converge\Auth\Auth::allowsLegacyNoRolesFallback())

// Bug 2: CSS 类名统一
- .sidebar-dock { ... }
+ .dock-sidebar { ... }
```

## 教训

1. **测试必须覆盖登录态** — 公共页面 ≠ 产品界面
2. **php -l 不够** — 必须加模式检测 (变量/常量、类名交叉引用)
3. **CSS-HTML 耦合要自动化检查** — 人工 review 发现不了类名不一致
4. **E2E 冒烟测试要包含登录流程** — 最快发现"侧边栏没了"这种 P0 问题
