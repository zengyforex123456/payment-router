# Converge Memory Index

## 架构重构与方法论

| 文件 | 描述 |
|------|------|
| [converge-refactoring-methodology](converge-refactoring-methodology.md) | 从项目到平台 — 三层骨架抽离完整方法论 (Phase 1→4 + A→D) |
| [hexagonal-architecture-php-patterns](hexagonal-architecture-php-patterns.md) | PHP 六边形架构落地模式 — Contracts→Domain→Infrastructure 四层 + 端口/适配器 |
| [namespace-migration-automation](namespace-migration-automation.md) | 批量命名空间迁移自动化 — PHP脚本替代sed，77文件零失误 |
| [contract-assertion-testing](contract-assertion-testing.md) | verify-modules.php 边重构边测试 — 4项契约断言，28模块63/63通过 |
| [orthogonal-probes-architecture-vs-css](orthogonal-probes-architecture-vs-css.md) | ArchitectureProbe + LayoutProbe 正交探针 — 修复动作永不交叉 |
| [bayesian-ab-test-pure-math-extraction](bayesian-ab-test-pure-math-extraction.md) | StatisticalSignificance 纯函数提取 — 零IO + ABTestEngine委托 |
| [viewcontext-unified-template-permissions](viewcontext-unified-template-permissions.md) | ViewContext 统一模板权限 — Latte/PHP双管道，零$GLOBALS直读 |

## PHP + Alpine.js + HTMX 界面模式

| 文件 | 描述 |
|------|------|
| [converge-php-alpine-htmx-architecture-patterns](converge-php-alpine-htmx-architecture-patterns.md) | Converge PHP界面开发全模式 — 组件库/令牌迁移/布局一致性/Alpine集成 |
| [alpine-js-not-loaded-dashboard-layout](alpine-js-not-loaded-dashboard-layout.md) | Alpine.js CDN 未加载导致看板页所有交互静默失败 |
| [alpine-php-dock-btn-missing-click](alpine-php-dock-btn-missing-click.md) | PHP heredoc 遗漏 Alpine @click 指令，dockBtn 按钮无反应 |
| [alpine-x-text-null-renders-literal-null](alpine-x-text-null-renders-literal-null.md) | json_encode(null) → JS null → x-text 显示字面量 "null" |
| [cdn-blocked-china-alpine-htmx](cdn-blocked-china-alpine-htmx.md) | unpkg.com 国内被封 → Alpine+HTMX 未定义 → 侧边栏无响应 |
| [alpine-fetch-interceptor-self-heal](alpine-fetch-interceptor-self-heal.md) | Alpine.js fetch 拦截器 — 防 PHP 崩溃返回 HTML 导致白屏 |
| [sed-destroys-js-syntax](sed-destroys-js-syntax.md) | sed 破坏 JS 语法 → 线上报错，改用 safe-replace.js + node --check 门禁 |

## 设计令牌与 CSS

| 文件 | 描述 |
|------|------|
| [css-token-self-reference-cycle](css-token-self-reference-cycle.md) | `--surface-raised: var(--surface-raised)` 自引用死循环导致 1.2:1 对比度 |
| [token-validation-automation](token-validation-automation.md) | validate-tokens.php 五道自检门禁 — 自引用/缺失/对比度/间距/命名 |
| [spacing-compliance-gate](spacing-compliance-gate.md) | 8px 网格间距合规门禁 — 442文件，1017处历史修复 |
| [dock-layout-css-leakage](dock-layout-css-leakage.md) | 旧 CSS margin 泄露进入 dock 模式 — 3 修复 + 视觉回归预防 |
| [landing-page-design-system](landing-page-design-system.md) | 着陆页设计系统 — 三级令牌/灰度先行/Dark Mode/12列网格 |

## 安全加固

| 文件 | 描述 |
|------|------|
| [converge-p0-p2-security-patches](converge-p0-p2-security-patches.md) | P0+P1+P2 上线前安全/运维缺口全部修复 — HTTPS/CSRF/租户隔离/会话/监控/CORS |
| [sql-injection-parametrize-php-mysqli](sql-injection-parametrize-php-mysqli.md) | PHP mysqli 参数化查询标准模式 — 10处拼接 → prepare+bind_param |
| [session-fixation-regenerate-before-write](session-fixation-regenerate-before-write.md) | Session 固定漏洞 — 必须先换 ID 再写敏感数据 |
| [php-close-tag-in-comment-bug](php-close-tag-in-comment-bug.md) | PHP 注释中 `?>` 提前闭合标签导致后端执行漏洞 |

## Docker 部署与运维

| 文件 | 描述 |
|------|------|
| [converge-docker-local-then-deploy](converge-docker-local-then-deploy.md) | Docker 标准流程 — 本地测试通过 → 推送镜像 → 服务器一键部署 |
| [converge-three-deploy-methods](converge-three-deploy-methods.md) | 三种部署方案对比 — Docker镜像/Git Pull/SCP直推 |
| [docker-deploy-error-patterns](docker-deploy-error-patterns.md) | Docker 部署 7 种错误指纹 — TLS/迁移编号/缺文件/端口冲突/heredoc/OPcache |
| [docker-schema-not-initialized](docker-schema-not-initialized.md) | Docker 数据库 Schema 未初始化导致 500 — 3层防御机制 |
| [docker-production-data-safety](docker-production-data-safety.md) | 生产级数据安全方案 — Volume持久化/3-2-1备份/自愈三层/密钥管理 |
| [docker-local-test-before-deploy](docker-local-test-before-deploy.md) | Docker 本地先测再 push — 11次→1次，44min→3min |

## Latte 模板引擎

| 文件 | 描述 |
|------|------|
| [latte-error-patterns-complete](latte-error-patterns-complete.md) | 6 类 Latte 错误指纹全集 — {do echo}/CSS冲突/@iteration/null→filter/__过滤器 |
| [latte-css-js-syntax-conflict](latte-css-js-syntax-conflict.md) | Latte 中 CSS/JS 语法冲突 — 3种官方方案 + n:syntax="off" 最佳实践 |
| [latte-dot-is-concatenation-not-property](latte-dot-is-concatenation-not-property.md) | `{$arr.key}` 是拼接不是属性访问 — 用 `{$arr['key']}` 替代 |
| [latte-syntax-pitfalls](latte-syntax-pitfalls.md) | Latte 3 语法陷阱全集 — {return}/正则!!empty/JS花括号/include路径 |
| [latte-double-underscore-filter-rejected](latte-double-underscore-filter-rejected.md) | Latte `__` 过滤器名被拒绝 — php -l通过但运行时LogicException |

## 布局与界面修复

| 文件 | 描述 |
|------|------|
| [main-grid-unclosed-footer-trapped](main-grid-unclosed-footer-trapped.md) | `<main class="grid">` 未 `</main>` 导致 footer 卡在 12 列网格 |
| [login-page-footer-flex-row-layout-bug](login-page-footer-flex-row-layout-bug.md) | flex-direction:row 导致 footer 内容并排出现在卡片右侧 |
| [php-file-encoding-corruption-patterns](php-file-encoding-corruption-patterns.md) | PHP 文件编码损坏 — emoji/em-dash 损坏 + 批量修复模式 |
| [powershell-setcontent-encoding-corruption](powershell-setcontent-encoding-corruption.md) | PowerShell Set-Content 编码损坏 — 禁止PS编辑PHP，用PHP脚本替代 |

## 多租户与SaaS

| 文件 | 描述 |
|------|------|
| [converge-multitenant-p0-p1](converge-multitenant-p0-p1.md) | 多租户隔离 P0 地基止血 + P1 回填 — MySQL 无 RLS 用应用层收口 |
| [converge-debranding-5layers](converge-debranding-5layers.md) | Converge 去身份化 5 层 — 品牌移除 + 白标就绪 |
| [converge-p2-completion-status](converge-p2-completion-status.md) | 上线冲刺 P0/P1/P2 完成状态 — 2026-07-17 全部通过 |

## 能力索引与模块

| 文件 | 描述 |
|------|------|
| [converge-capability-index](converge-capability-index.md) | 70+ 可复用模块能力索引 — 12 类别 + 调用签名 + 门控开关速查 |
| [email-service-capability](email-service-capability.md) | EmailService 统一邮件发送 — PHPMailer+mail() 双通道 |
| [converge-snapshot-system-gap](converge-snapshot-system-gap.md) | 快照系统 5 处断链修复 + 降级链完整 |

## 门禁与验证

| 文件 | 描述 |
|------|------|
| [precommit-hook-scans-full-codebase](precommit-hook-scans-full-codebase.md) | pre-commit 门禁扫描全量而非仅 staged — 改2文件报200+历史违规 |
| [sidebar-four-bugs-blind-spot](sidebar-four-bugs-blind-spot.md) | php -l 漏 4 类运行时错误 — 侧边栏全漏检分析 + 3层防御 |
| [script-proliferation-root-cause](script-proliferation-root-cause.md) | 22 个部署脚本泛滥根因分析 — 可发现性=重复的抗体 |
| [tool-mesh-migration-pattern](tool-mesh-migration-pattern.md) | 零散脚本 → #[Tool] 统一命令迁移 — 6步迁移 + 两次验证 |
| [six-cap-framework-injection](six-cap-framework-injection.md) | 六可框架层注入 ToolContext+ToolRunner — Sidecar模式零侵入 |

## 布局组件

| 文件 | 描述 |
|------|------|
| [grid-container-refactoring-leftover-tags](grid-container-refactoring-leftover-tags.md) | Grid::container 重构遗留标签 — stray `</div>` + missing `</main>` |

---

**50 个记忆文件迁移完成** — 2026-07-19

源目录: `C:\Users\Administrator\.claude\projects\D--project-zhice-os\memory\`
目标目录: `D:\project\zhice-os\projects\converge\.claude\memory\`
