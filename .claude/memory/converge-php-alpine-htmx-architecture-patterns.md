---
name: converge-php-alpine-htmx-architecture-patterns
description: Converge PHP界面开发全模式 — 组件库/令牌迁移/布局一致性/Alpine集成
metadata: 
  node_type: memory
  type: project
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# Converge PHP + Alpine + HTMX 界面开发模式全集

> 来源: 2026-07-13~14 为期2天的全面界面重构
> Token覆盖率: 7.2%→56.5% · 阻塞文件: 6→0 · 组件: 2→11

## 一、架构: 双布局 + 三层复用

```
公共页 (5个):                    看板页 (20+个):
_layout-head.php                 v2.php
  ├─ tokens.css                    ├─ tokens.css
  ├─ tailwind.min.css              ├─ app-bundle.css (5→1合并)
  ├─ Alpine.js CDN ✅             ├─ Alpine.js CDN ✅ (曾缺失!)
  ├─ HTMX CDN ✅                  ├─ HTMX CDN ✅ (曾缺失!)
  ├─ 导航栏                        ├─ _dock-sidebar.php (Alpine)
  └─ <body>                        │   ├─ dockBtn() @click + :class
_layout-foot.php                   │   ├─ 最近访问 (localStorage)
  └─ 页脚 + dark mode JS           │   └─ 搜索框
                                   ├─ _cmd-palette.php (Ctrl+K)
                                   ├─ _shortcuts-help.php (?)
                                   ├─ _upgrade-prompt.php (超额引导)
                                   ├─ _onboarding-checklist.php (新手引导)
                                   └─ <main> + 内容容器 (hx-boost)
```

**关键教训**: 两套布局必须加载同样的 CDN 脚本。缺失 → 所有交互静默失败。

## 二、PHP 组件库模式 (ui-components.php)

### 函数签名约定
```php
// 每个组件 = 一个纯 PHP 函数，返回 HTML 字符串
function ComponentName(string $primary, mixed $secondary, array $opts = []): string
```

### 已建立的 9 个组件
| 组件 | 用途 | 使用页数 |
|------|------|:---:|
| StatCard($label, $value, $format) | 统计卡片 | 1 (dashboard) |
| Badge($text, $variant) | 状态标签 | 5+ |
| Card($content, $opts) | 内容卡片 | 可泛化 |
| EmptyState($icon, $title, $desc, $url, $label) | 空状态 | 4+ |
| PageHeader($title, $desc, $actions) | 页面标题 | 可泛化 |
| ConfirmLink($url, $label, $msg) | 确认链接 | 可泛化 |
| StatusDot($status, $label) | 状态点 | 3+ |
| DataTable($columns, $rows) | 数据表格 | 1 |
| InfoBar($items, $rightText) | 信息条 | 1 |

### 新组件接入流程
1. 在 `includes/ui-components.php` 添加函数
2. 在 `index.php` 已全局 require_once
3. 任何视图直接调用: `<?= ComponentName(...) ?>`

## 三、设计令牌迁移策略

### 三阶段推进
```
Phase 1: 替换高频通用色 (#666/#ddd/#fff/#999 → semantic tokens)
Phase 2: 替换品牌相关色 (#3d5a26/#d32f2f/#2196F3 → functional tokens)
Phase 3: 替换 3-digit hex (#333/#eee/#ccc → semantic tokens)
```

### 批量替换脚本模板
```php
$map = [
    '/#666\b/' => 'var(--content-secondary)',
    '/#ddd\b/' => 'var(--border-default)',
    '/#fff\b/' => 'var(--surface-raised)',
];
foreach ($map as $pat => $rep) {
    $content = preg_replace($pat, $rep, $content);
}
```

### 关键教训
- 3-digit hex (#666, #ddd) 占据大量硬编码，容易被忽略
- 每个文件先用 `grep -oP` 统计 top 20 颜色，再批量映射
- 替换后立即跑 `php -l` + `check-ui-compliance.php` 验证

## 四、5 大常见 Bug 及预防

| # | Bug | 根因 | 检测 |
|:--:|------|------|------|
| 1 | Dock 按钮无反应 | Alpine.js CDN 未在 v2.php 加载 | `check-consistency.php` |
| 2 | @click 不触发 | PHP 函数输出遗漏 Alpine 指令 | `check-consistency.php` |
| 3 | Alpine 完全不初始化 | x-data 中 JSON 双引号未转义 | `check-consistency.php` |
| 4 | 编码损坏 (emoji→?) | PowerShell `Set-Content` 破坏 UTF-8 | `php -l` 检查 |
| 5 | 部署后未生效 | OPcache 未清除 | `docker exec ... opcache_reset()` |

## 五、自动化防御体系

```
pre-commit:
  ⓪ check-consistency.php   Alpine/HTMX/布局 (30ms)
  ① Token coverage           >30% 达标
  ② Grayscale safety         <50% unsafe
  ③ Staged files             <20 new hex

手动:
  e2e-test.php              103 项 (PHP include级)
  check-ui-compliance.php   112 文件扫描
  test-navigation.php       23 页面 HTTP 测试
```

## 六、CSS 合并策略

```
Before: tokens.css + main.css + dock-layout.css + intent-ui.css + skeleton.css + toast.css = 6 HTTP请求
After:  tokens.css + app-bundle.css = 2 HTTP请求

合并方式: cat main.css dock-layout.css intent-ui.css skeleton.css toast.css > app-bundle.css
注意: tokens.css 必须独立加载 (设计系统基础，公共页也依赖)
注意: 修改任意源文件后需重建 app-bundle.css
```

## 七、部署流水线

```
git push → GitHub Actions:
  1. SSH 连接服务器
  2. git reset --hard origin/main
  3. docker compose up -d --build
  4. 健康检查 (20次重试)
  5. OPcache 清除

秘密: SSH_HOST + SSH_PRIVATE_KEY (PEM 格式，含头尾)
```

**关联**: [[converge-dev-patterns]] [[alpine-js-not-loaded-dashboard-layout]] [[alpine-php-dock-btn-missing-click]] [[php-file-encoding-corruption-patterns]] [[github-actions-ssh-deploy-secret-format]]
