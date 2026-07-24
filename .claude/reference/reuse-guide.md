# Converge 四层复用指南 — 用 Claude 启动新项目

> 基于 converge 生态的四层复用体系。新项目跳过 80% 基建，直接写业务。

## 四层复用模型

```
🧠 Rules 层 (指令集)    CLAUDE.md + .claude/reference/  → Claude 自动遵守架构
🏗️ Skeleton 层 (文件系统)  converge-skeleton 模板           → 目录结构 + CI/CD
🧩 Core 层 (内核)        converge-core Composer 包         → 认证/权限/日志/熔断/断言
🐳 Base 层 (硬件)        converge-base Docker 镜像         → PHP 8.2 + Nginx + 扩展
```

## 5 分钟启动新项目

```bash
# Step 1: 复制骨架
cp -r converge-skeleton my-new-app && cd my-new-app

# Step 2: 初始化
bash scripts/reset-for-new-project.sh MyNewApp

# Step 3: 定制 CLAUDE.md
# 把 {{PROJECT_NAME}} 替换为项目名
# 填写模块地图

# Step 4: 验证
php -S localhost:8080 -t public/

# Step 5: 创建第一个模块
bash ../converge-core/scripts/dev/module-scaffold.sh FeatureName
```

## 每层详细说明

### 🐳 Base 层 — Docker 环境

```dockerfile
# 新项目 Dockerfile 只需一行:
FROM converge-base:latest

# 继承: PHP 8.2 + Nginx + Composer + mysqli/mbstring/zip/redis
# 只需 COPY 业务代码
COPY . /var/www/app
```

### 🧩 Core 层 — 业务能力

```json
// composer.json
{
    "repositories": [{"type": "path", "url": "../converge-core"}],
    "require": {"converge/core": "^1.0"}
}
```

开箱即用的能力:

| 场景 | 代码 | 说明 |
|------|------|------|
| 用户登录 | `Auth::login($u, $p)` | Argon2ID + 速率限制 + 记住我 |
| 权限检查 | `Permission->can('resource.edit')` | RBAC 4角色22权限 |
| 模块通信 | `Hooks::doAction('user.registered')` | WordPress 风格 |
| 外部API保护 | `new CircuitBreaker('api', 3)` | 三态断路器 |
| 日志 | `$logger->info('...', ['id'=>1])` | PSR-3 JSON 结构化 |
| 健康检查 | `HealthChecker->health()` | 4维: DB/Redis/GeoIP/磁盘 |
| 翻译 | `__('sidebar.settings')` | zh/en 自动检测 |
| 生产断言 | `AssertionEngine::assert(...)` | 6层22项 <1ms |
| Stimulus安全 | `json_encode($data, JSON_HEX_APOS \| JSON_HEX_TAG)` | XSS 防护 (window.__DATA 注入) |

### 🏗️ Skeleton 层 — 项目模板

```
my-app/
├── modules/           ← 业务模块 (六边形架构)
│   └── {Name}/
│       ├── Domain/    ← 实体 + 端口 (零 IO)
│       ├── Application/ ← UseCase 编排
│       ├── Infrastructure/ ← 适配器
│       ├── Controller/    ← HTTP 入口 ≤15行/方法
│       └── bootstrap.php  ← Hook 注册
├── public/            ← Web 入口
├── config/            ← 环境配置
├── .github/workflows/ ← CI/CD 流水线
└── CLAUDE.md          ← Claude 架构约束
```

### 🧠 Rules 层 — Claude 知识

```
.claude/
├── prd.md              ← 需求文档 (权威来源)
└── reference/
    ├── capability-catalog.md  ← converge-core 能力目录
    ├── design-tokens.md       ← UI 设计令牌
    ├── dev-workflow.md        ← 开发流程详解
    └── reuse-guide.md         ← 本文件
```

## 新项目 checklist

```
□ cp -r converge-skeleton new-app && cd new-app
□ bash scripts/reset-for-new-project.sh ProjectName
□ 修改 CLAUDE.md: 项目名 + 模块地图
□ composer.json: 确认 converge/core 依赖
□ composer install
□ 创建第一个模块: bash ../converge-core/scripts/dev/module-scaffold.sh {Name}
□ 按编码顺序写: Domain → Port → UseCase → Infrastructure → Controller → bootstrap
□ php ../converge-core/scripts/dev/verify-modules.php
□ git init && git commit -m "feat: init from converge-skeleton"
```

## content-os 验证案例

基于本指南的实战验证 — 3 个模块，22 文件，72/72 契约断言:

| 模块 | 文件 | 业务逻辑 | 基建复用 |
|------|:--:|------|------|
| Content (选题库) | 6 | Topic 实体 + 4态状态机 | Hooks + DatabaseInterface |
| Pipeline (内容) | 7 | Content 实体 + 审核流 | Hooks + DatabaseInterface |
| Ai (AI写作) | 9 | AiTask + Provider端口 | LlmKeyResolver + Hooks |

**关键数据**: 22 个文件中，Domain 层零 IO，跨模块仅 Hooks 通信，零架构违规。CI 门禁 1 秒通过。
