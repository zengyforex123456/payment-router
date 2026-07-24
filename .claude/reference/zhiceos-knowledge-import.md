# 智策OS 知识导入 — Converge 适用条目索引

> 生成日期: 2026-07-17
> 来源: 智策OS Memory (C:\Users\Administrator\.claude\projects\D--project-zhice-os\memory\)
> 用途: 将 260+ 通用记忆中最相关的 20 条收敛到 Converge PHP/Latte/Alpine.js SaaS 上下文

---

## Latte 模板引擎

### [Latte] 1. Latte 5 类错误指纹全集
- **文件**: memory/latte-error-patterns-complete.md
- **检测**: `Latte\CompileException` 5 种子类型 — `{do echo}` 反模式、CSS/JS `{letter}` 冲突、`{literal_text}` 代码示例、Smarty `@iteration` 迁移、`null->filter` TypeError
- **适用**: Converge 39 个 Latte 模板均可能触发。`{do echo}` 已影响 7 模板，`{letter}` 冲突影响 14 模板。迁移期高频报错。
- **关键要点**: 每种错误有对应自愈脚本（preg_replace / fix-latte-script-syntax.php）。部署前必须跑 `test-latte-compile.php` 预编译验证。

---

### [Latte] 2. Latte CSS/JS 语法冲突三种解决方案
- **文件**: memory/latte-css-js-syntax-conflict.md
- **检测**: `Latte\CompileException: Unexpected '{'` 或 `Unexpected ';'` 在 `<style>` 或 `<script>` 块内
- **适用**: Converge `<style n:syntax="off">` 和 `<script n:syntax="off">` 是最佳方案。纯 CSS/JS 块零修改。混合场景（JS 需 Latte 变量）用 `n:syntax="double"` + 双花括号。
- **关键要点**: 新模板 `<script>` 块必须考虑 Latte 语法冲突。`fix-latte-script-syntax.php` 是幂等自愈脚本，可在 CI 中运行。

---

### [Latte] 3. Latte `__` 过滤器名被拒绝
- **文件**: memory/latte-double-underscore-filter-rejected.md
- **检测**: `PHP Fatal error: Uncaught LogicException: Invalid filter name '__'` — `php -l` 通过但运行时 HTTP 500
- **适用**: Converge 使用 `__()` 翻译函数，Latte 中过滤器名用 `|t` 或 `|trans` 别名替代 `|__`。所有 `.latte` 模板用 `|t`。
- **关键要点**: `php -l` 无法捕获此问题。预提交门禁需增加 Latte 模板编译验证。

---

### [Latte] 4. 登录页 Footer 在卡片右侧而非底部
- **文件**: memory/login-page-footer-flex-row-layout-bug.md
- **检测**: 登录页 Docs/Terms/Privacy 链接出现在卡片右侧，而非底部居中
- **适用**: `body { display: flex; }` 默认 `flex-direction: row`，登录卡片和 footer 被并排渲染。需加 `flex-direction: column`。
- **关键要点**: 所有居中布局页面必须检查 `flex-direction`，尤其是多子元素的 body。`flex` 默认主轴为 row。

---

### [Latte] 5. Latte 界面六可评估框架
- **文件**: memory/latte-six-capability-assessment.md
- **检测**: Latte 模板可观察性/可追溯性/可审计性/可验证性/可进化性/可自愈性 6 维评分
- **适用**: Converge Latte 模板当前 12/30 分，目标 24/30。关键缺口: L1 Token 闭包（CSS 令牌双向验证）、模板 git 恢复、视觉回归测试。
- **关键要点**: LayerAssertion 四层断言（L1 Token/L2 Component/L3 Template/L4 Page）已部分实现。新 Latte 模板应注入 `<!-- L3:OK name hash -->` 标记。

---

## 前端 Alpine.js / JS

### [前端] 6. Alpine.js 未加载 — Dashboard 所有交互失效
- **文件**: memory/alpine-js-not-loaded-dashboard-layout.md
- **检测**: 浏览器 Console `Alpine is not defined` 或 `Alpine.data is not a function`，页面 `x-data`/`@click`/`x-show` 全部忽略
- **适用**: Converge 有两套布局 — `_layout-head.php`（公共页）和 `v2.php`（看板页）。v2.php 曾缺失 Alpine CDN，导致所有侧边栏/Ctrl+K/? 交互静默失败。
- **关键要点**: 新布局模板必须包含 Alpine + HTMX CDN 或本地 JS 文件。两套布局的脚本应提取到公共 include。

---

### [前端] 7. Alpine + PHP: dockBtn() 按钮无点击反应
- **文件**: memory/alpine-php-dock-btn-missing-click.md
- **检测**: Alpine 组件方法存在但按钮无响应 / 侧边栏点不动 / `switchDock is not defined`
- **适用**: PHP `dockBtn()` 用 heredoc 输出 HTML 但未包含 Alpine `@click` 和 `:class` 指令。PHP 函数生成的 HTML 若在 Alpine `x-data` 容器内，必须显式输出 Alpine 指令。
- **关键要点**: PHP 函数输出 HTML 的通用模式：函数签名为 `function ComponentName(string $primary, mixed $secondary, array $opts = []): string`，返回字符串。在 Alpine 容器内使用时，函数输出必须包含 `@click`/`x-show`/`:class`。

---

### [前端] 8. Alpine.js `x-text` 渲染字面量 "null"
- **文件**: memory/alpine-x-text-null-renders-literal-null.md
- **检测**: 页面显示 `"null"` 字符串 — PHP `json_encode(null)` 输出 JS `null`，Alpine `x-text` 调用 `.toString()` 转为 `"null"`。
- **适用**: Converge 模板中所有 PHP 到 Alpine 的数据传递使用 `json_encode($value ?: '')` 而非 `json_encode($value ?: null)`。配合 `x-show="error"` 空值自动隐藏。
- **关键要点**: `json_encode(null)` !== `""`。这是 PHP+Alpine 桥接的常见陷阱。模板中和 `x-text` 配合使用空字符串而非 null。

---

### [前端] 9. CDN 被拦截 — Alpine.js/HTMX 未加载
- **文件**: memory/cdn-blocked-china-alpine-htmx.md
- **检测**: 浏览器 Console `Alpine is not defined` + 页面交互无响应（`unpkg.com` 国内不稳定）
- **适用**: Converge 已将 Alpine.js 下载到 `public/assets/js/alpinejs.min.js`（46KB），HTMX 到 `public/assets/js/htmx.min.js`（51KB）。CDN 引用改为本地路径。
- **关键要点**: 新项目不依赖 CDN。`project-scaffold.sh` 已自动下载到本地。模板使用 `<script src="/assets/js/alpinejs.min.js?v=3">`。

---

### [前端] 10. sed 破坏 JS 语法 — 检测/预防/替代
- **文件**: memory/sed-destroys-js-syntax.md
- **检测**: 浏览器 Console `Uncaught SyntaxError: Unexpected token ')'` + 行号指向文件末尾
- **适用**: sed 对 JS/HTML 文件做字符串替换时未处理特殊字符（`/`, `$`, `&`, `\`），括号不匹配致 SyntaxError。Converge 侧边栏 Alpine 组件曾因 sed 损坏 4 个 JS 文件。
- **关键要点**: 三层防御：L1 `scripts/safe-replace.js` 替代 sed（自动备份）、L2 `node --check` 门禁、L3 ESLint 规范。Converge 项目中 PHP 批量替换也用 PHP 脚本而非 sed。

---

## PHP 架构

### [架构] 11. PHP 六边形架构落地模式
- **文件**: memory/hexagonal-architecture-php-patterns.md
- **检测**: Domain 目录中出现 `use Illuminate\` / `new mysqli` / `new PDO` — 内核污染
- **适用**: Converge 28 个六边形模块按四层模板创建：`Domain/{Name}.php`（纯业务/零 IO）、`Domain/{Name}RepositoryInterface.php`（端口）、`Application/{Name}UseCase.php`（编排）、`Infrastructure/Mysql{Name}Repository.php`（适配器）、`Controller/{Name}Controller.php`（HTTP 入口 <=15 行）。
- **关键要点**: 六边形铁律：Controller->UseCase->RepositoryInterface<-Infrastructure，Domain 在最内层零框架依赖。换数据库只需新建 Adapter，Domain 零改动。

---

### [架构] 12. Converge 三层骨架抽离完整方法论
- **文件**: memory/converge-refactoring-methodology.md
- **检测**: 重构中每迁移一个模块需要立即验证 4 项契约，不等 CI
- **适用**: Converge 经历了 Phase 1-4（骨架提取）和 Phase A-D（业务迁移），涉及 77 个文件、28 个模块、7 个 Contracts、3 个仓库。
- **关键要点**: 四项验证机制 — `verify-refactoring.php`（39 断言）、`verify-modules.php`（4 契约）、`enforce-architecture.sh`（9 条规则）、`pre-deploy-check.sh`（5 项检查）。每次拆分后立即跑测试 + validate 确认无回归。

---

### [架构] 13. PHP 注释中 `*/5` 提前关闭注释
- **文件**: memory/php-comment-star-slash-termination.md
- **检测**: `Parse error: syntax error, unexpected token "*"` at docblock
- **适用**: PHP `/* */` 块注释遇到第一个 `*/` 就结束。cron 表达式 `*/5 * * * *` 中的 `*/` 被解析为注释结束符。Converge 中的 cron 配置注释需改为行注释或文字描述。
- **关键要点**: 块注释中永远不要包含 `*/` 序列。cron 表达式用英文描述替代（如 "every 5 min"）。

---

## 安全加固

### [安全] 14. PHP mysqli SQL 注入参数化标准模式
- **文件**: memory/sql-injection-parametrize-php-mysqli.md
- **检测**: `->query("...{$var}...")` 或 `->query("...' . $var . '...")` 拼接模式
- **适用**: Converge 使用 `prepare() + bind_param('is', $a, $b)` 标准模式。类型码 `i`=int、`s`=string、`d`=double、`b`=blob。算术注入先在 PHP 算好再 bind。
- **关键要点**: OWASP #1 风险。`grep -rn '\->query(".*\$' src/ --include="*.php"` 扫零残留。CI 应加 grep 阻断直接拼接。

---

### [安全] 15. Session 固定漏洞：regenerate_id 顺序
- **文件**: memory/session-fixation-regenerate-before-write.md
- **检测**: `session_regenerate_id(true)` 出现在 `$_SESSION['user_id'] = ...` 之后
- **适用**: Converge 登录流程必须遵循：`session_start() -> 验证密码 -> session_regenerate_id(true) -> $_SESSION['user_id'] = ...` 的顺序。先写敏感数据再换 ID 是 Session Fixation 漏洞。
- **关键要点**: OWASP A2 级风险。修复只需调整调用顺序，零成本。`grep -n 'session_regenerate_id'` 检查相对行号。

---

## CSS / 设计系统

### [CSS] 16. CSS :root 变量多文件冲突
- **文件**: memory/css-root-variable-conflict.md
- **检测**: 同一 `--c-*` 变量在 >=2 个 CSS 文件的 `:root {}` 块中被定义不同值，后加载者胜出
- **适用**: Converge 设计令牌迁移期常见问题。旧 `:root` 块与新设计令牌冲突致颜色突变（如按钮白字白底不可见）。
- **关键要点**: 每次新增 CSS 文件或在 `:root` 中定义变量后运行 `node tools/css-audit.cjs src/` 检查第 4 关变量冲突。最佳方案是删除旧 `:root` 块中的冲突变量定义。

---

### [CSS] 17. 着陆页设计系统全链路
- **文件**: memory/landing-page-design-system.md
- **检测**: 设计系统从内联 CSS 到三级令牌演进的完整经验
- **适用**: Converge 着陆页使用 Tailwind 处理布局/间距/响应式，设计令牌处理颜色语义。三级令牌架构：L0 Primitives（颜色值）、L1 Semantic（surface/content/border/accent）、L2 Functional（success/danger/warning）。
- **关键要点**: 灰度先行方法论 — 用 `content-primary/secondary/tertiary` + `surface-base/raised/overlay` 建立结构，品牌色只通过 `accent` 令牌。Dark Mode 用 CSS 自定义属性 + Tailwind `<alpha-value>` 模式零 HTML 改动。

---

## 测试与质量

### [测试] 18. 边重构边测试 — 契约断言模式
- **文件**: memory/contract-assertion-testing.md
- **检测**: 重构中每迁移一个模块，需立即验证 4 项契约但不等 CI（CI 反馈太慢）
- **适用**: `verify-modules.php` 每模块 4 项断言：类存在、纯内存实例化（不连 DB）、Domain 纯净（0 处 `use Illuminate`/`new mysqli`/`new PDO`）、有业务方法（非贫血模型）。
- **关键要点**: 0 编写成本、毫秒级运行。已在 Converge 28 模块验证，63/63 通过。预部署脚本 `pre-deploy-check.sh` 包含此检查。

---

### [测试] 19. 侧边栏 4 Bug 全漏检 — 测试盲区
- **文件**: memory/sidebar-four-bugs-blind-spot.md
- **检测**: `empty($UNDEFINED_VAR)` 恒 true、CSS/HTML 类名不一致、未定义静态方法、缺少构造参数 — 35 个测试全部漏检
- **适用**: Converge 登录后 UI（侧边栏/Dock/导航/仪表盘）有 0 测试覆盖率。`php -l` 无法捕获变量/常量混淆、类名交叉引用、运行时 fatal error。
- **关键要点**: 三层防御 — L1 `scripts/lint-php-patterns.sh`（模式检测）、L2 `scripts/lint-css-classes.sh`（类名交叉引用）、L3 `tests/E2E/auth-ui-smoke.spec.js`（登录后冒烟）。

---

## Docker 部署

### [Docker] 20. Docker 部署常见错误 7 种指纹
- **文件**: memory/docker-deploy-error-patterns.md
- **检测**: TLS/SSL 自签名证书、缺表 500、迁移编号冲突、Docker build 缺文件、端口冲突、heredoc PHP 不解析、OPcache 缓存旧代码
- **适用**: Converge Docker 全链路部署已从 11 次/44 分钟降到 1 次/3 分钟。
- **关键要点**: 关键预防 — `scripts/local-test.sh` 每次提交前跑（build->health->36 项 UI test->语法检查）。Docker build 每次 `--no-cache` 确保 OPcache 不缓存旧代码。`depends_on service_completed_successfully` 确保 migrator 先完成。

---

## 对照表：Converge 现有规则 vs 知识导入

| Converge 规则文件 | 知识导入补充 |
|---|---|
| `02-ui-architecture.md` | #6 Alpine 双布局 CDN 缺失、#7 PHP 函数 Alpine 指令遗漏、#8 json_encode null 陷阱 |
| `latte-best-practices.md` | #1-5 Latte 全部 5 条 — 错误指纹、语法冲突、过滤器名、flex 布局、六可评估 |
| `03-architecture-fitness.md` | #11 六边形架构四层模板、#12 重构方法论与验证机制 |
| `03-coding-standards.md` | #13 注释 `*/` 提前关闭、#10 sed 破坏 JS 语法 |
| `04-security-standards.md` | #14 SQL 注入参数化标准、#15 Session regenerate 顺序 |
| `05-test-standards.md` | #18 契约断言模式、#19 4 Bug 全漏检分析 |
| `08-deploy-git.md` | #20 Docker 7 种错误指纹 |
| `11-prd-format.md` | #17 设计系统全链路（设计文档参考） |

---

## 使用建议

1. **遇到 Latte 编译错误**：先查 #1 错误指纹表，对号入座用自愈脚本
2. **Alpine 交互静默失败**：先查 #6 CDN 缺失、#9 CDN 被拦截
3. **重构模块**：参考 #11 六边形模板、#12 迁移方法论、#18 契约断言
4. **写安全的 SQL**：参考 #14 参数化模式，CI 加 grep 阻断
5. **CSS 颜色异常**：参考 #16 变量冲突检测、#17 三级令牌架构
6. **Docker 部署失败**：参考 #20 错误指纹表快速诊断
7. **写新 <script> 块**：参考 #2 n:syntax="off" 防止 Latte 语法冲突
8. **登录行为**：参考 #15 session_regenerate_id 顺序
