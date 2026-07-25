# DevOps 架构元规则 — 分层·对象模型·八阶段·六可横切·JSON 通信

> 层: L0 元规则 | 版本: v2.0 | 适用: Converge DevOps 全流程
> 依赖: `00-architecture-meta.md` (全局架构四原则)

## Trigger

新功能部署 / 故障恢复 / CI 触发 / "部署" "devops" 关键字 / P5 部署阶段

## Input

- 源代码 (git push 触发)
- PRD 需求清单 (来自 `11-prd-format.md`)
- 服务器状态 (Dokku apps + Docker containers)

## Action

### 一、DevOps 对象模型 (所有被管理的东西都是对象)

**对象层级**: Project 是顶级容器，包含 App/Database/Backup/Secret 等子对象。

```
E:\project\  (本地开发机)                  137.184.225.93 (服务器)
══════════════════════                    ═══════════════════
                                          Host: 137.184.225.93       ← Host 对象
Project: converge-skeleton ──部署──→        │
│  .deploy.json                            ├── App: payment-router    ← App 对象
│  type: php-standalone                    │   ├── Domain: paymentrouter.vip
│  domain: paymentrouter.vip               │   ├── Database: payment-db ← DB 对象
│  services: [payment-router]              │   ├── Secrets: 4 keys    ← Secret 对象
│  database: payment_router                │   ├── Deployments: [...]  ← Deployment 对象
│  secrets: [DB_PASSWORD, STRIPE_KEY...]   │   ├── HealthCheck: /health ← HealthCheck 对象
│                                          │   └── ResourceLimit: 256M ← ResourceLimit
Project: converge ──部署──→                │
│  .deploy.json                            ├── App: converge           ← App 对象
│  type: php-fpm-nginx                     │   ├── Domain: converge.sale
│  domain: converge.sale                   │   ├── Database: converge-db
│  note: 基础设施容器                        │   └── HealthCheck: GET /
│                                          │
Project: adscope ──部署──→                 ├── App: adscope             ← App 对象
│  .deploy.json                            │   ├── Domain: adscope.vip
│  type: python-fastapi                    │   ├── Database: adscope-db
│  domain: adscope.vip                     │   ├── Secrets: 4 keys
│  services: [api, scraper]                │   ├── HealthCheck: /api/health
│  database: adscope                       │   └── Deployments: [...]
│                                          │
Project: deploy-manager (本工具)            ├── Backups: 每天凌晨        ← Backup 对象
  自身不部署，管理其他项目                    ├── Alerts: cpu/mem/502     ← Alert 对象
                                          └── Events: EventStore       ← Event 对象
```

**关键关系**: Project (本地 `.deploy.json`) 1:1→ App (远程 Dokku)。Project 是"意图"，App 是"实现"。Project 变更 → 触发 App 部署。Project 删除 → App 标记 orphan。

### 二、对象类型定义 (10 种)

| 类型 | 发现方式 | 唯一标识 | 属性 |
|------|------|------|------|
| `host` | 配置文件 `HOSTS` | ip:port | os, dokku_version, cpu, memory, disk |
| `project` | 扫描 `E:\project\*\.deploy.json` | project name | type, domain, services[], secrets[], git_remote |
| `app` | `dokku apps:list` | app name | domain, git remote, deploy-branch, created_at |
| `database` | `dokku mysql:list` | db name | linked app, engine, version, exposed port |
| `secret` | `dokku config:get <app>` | app+key | value (redacted), set_at |
| `deployment` | `dokku git:report <app>` | app+commit | commit SHA, timestamp, deployer |
| `backup` | scan `/root/backups/` | filename | db, size, created_at, expires_at |
| `healthcheck` | read `.deploy.json` | app+path | url, interval, timeout, expected_status |
| `resourcelimit` | `dokku resource:report <app>` | app | memory_limit, cpu_limit, process_count |
| `alert` | config file | alert_id | type, threshold, action, enabled |

### 三、对象自动注册机制

```
发现 (Discovery)          注册 (Registration)        查询 (Query)
──────────────────        ──────────────────         ─────────────
dokku apps:list     →     registry.json          →   devops registry list
dokku mysql:list    →     {                       →   devops registry list --type app
dokku config:get    →       "objects": {          →   devops registry status
scan /root/backups  →         "app:converge": {   →   devops registry health
dokku git:report    →           "type": "app",
read .deploy.json   →           "props": {...},
                     →           "discovered_at": "...",
                     →           "health": "ok"
                     →         },
                     →         ...
                     →       },
                     →       "updated_at": "..."
                     →     }
```

**注册规则**:
- 新对象出现 → 自动注册到 registry
- 对象消失 → 标记 `health: "gone"` (不删除，保留审计)
- 对象属性变化 → 更新 props + 记录变更事件到 EventStore
- 每次 `devops registry refresh` → 全量重新发现 + diff

### 四、分层架构

```
┌──────────────────────────────────────────────────────────┐
│ L4: 六可横切层 (Cross-cutting)                            │
│   🔭可观察 📋可追溯 📐可审计 ✅可验证 🧬可进化 🩹可自愈      │
│   每个阶段 + 每个对象变更 → 六可验证门禁                      │
└──────────────────────────────────────────────────────────┘
         ↑ 验证                    ↑ 门禁结果
┌──────────────────────────────────────────────────────────┐
│ L2: DevOps 执行层 (8 阶段模块)                             │
│                                                          │
│  Plan ──→ Code ──→ Build ──→ Test ──→ Release            │
│   ↑                                          │            │
│   └──── Feedback ←── Monitor ←── Operate ←── Deploy      │
│                                                          │
│  每阶段操作对象 (app/db/backup...) → 读写 registry          │
└──────────────────────────────────────────────────────────┘
         ↑ 读写对象              ↑ 对象状态
┌──────────────────────────────────────────────────────────┐
│ L1.5: 对象注册表层 (Object Registry)                       │
│   registry.json ← 所有对象的唯一真相源 (Single Source)      │
│   discover.sh  ← 自动发现脚本 (每种对象一个 discover 函数)   │
│   registry.sh  ← 统一管理 CLI                              │
└──────────────────────────────────────────────────────────┘
         ↑ 规范
┌──────────────────────────────────────────────────────────┐
│ L3: 标准层 (JSON Schemas + Contracts)                      │
│   .claude/schemas/devops-*.json ← 接口定义                 │
│   .claude/schemas/devops-object-*.json ← 对象 schema       │
└──────────────────────────────────────────────────────────┘
         ↑ 调度
┌──────────────────────────────────────────────────────────┐
│ L1: 编排层 (Pipeline Orchestrator)                         │
│   读取 registry → 决定下一阶段 → 调度执行                    │
└──────────────────────────────────────────────────────────┘
```

### 五、模块清单 (22 模块)

**对象系统 (3)**:
| ID | 文件 | 层 | 单一职责 |
|:---|------|:---:|------|
| D00 | `devops-00-architecture.md` | L0 | 架构元规则（本文件） |
| D0R | `devops-0r-registry.md` | L1.5 | 对象注册表：发现→注册→查询 |
| D0S | `devops-0s-schemas.md` | L3 | 所有 JSON Schema 定义 |

**阶段模块 (8)**:
| ID | 文件 | 层 | 单一职责 |
|:---|------|:---:|------|
| D01 | `devops-01-plan.md` | L2 | 需求→任务拆解 |
| D02 | `devops-02-code.md` | L2 | Pre-commit 门禁 |
| D03 | `devops-03-build.md` | L2 | Docker 构建+密钥注入 |
| D04 | `devops-04-test.md` | L2 | 测试+安全扫描 |
| D05 | `devops-05-release.md` | L2 | Staging 部署+冒烟 |
| D06 | `devops-06-deploy.md` | L2 | 生产部署+健康检查 |
| D07 | `devops-07-operate.md` | L2 | 备份+资源+回滚 |
| D08 | `devops-08-monitor.md` | L2 | 日志+告警+仪表盘 |

**六可横切 (6)**:
| ID | 文件 | 层 | 单一职责 |
|:---|------|:---:|------|
| DO1 | `devops-obs-observability.md` | L4 | 🔭 可观察：所有对象状态可见 |
| DO2 | `devops-obs-traceability.md` | L4 | 📋 可追溯：所有事件可回溯 |
| DO3 | `devops-obs-auditability.md` | L4 | 📐 可审计：所有变更可审查 |
| DO4 | `devops-obs-verifiability.md` | L4 | ✅ 可验证：所有断言可证伪 |
| DO5 | `devops-obs-evolvability.md` | L4 | 🧬 可进化：新对象不改旧对象 |
| DO6 | `devops-obs-self-healing.md` | L4 | 🩹 可自愈：故障自动检测恢复 |

### 六、模块标准结构

```markdown
# D0X — Module Name

## Trigger
## Input
## Action
## Output
## Managed Objects (本模块管理的对象类型)
| 对象类型 | 操作 | 发现方式 |
## Six-Capability Gates
## Interface Contract
```

### 七、接口通信规范

**模块间 JSON-only，对象间 registry-only。**

```
阶段通信 (流水线):
  reports/devops-{stage}-result.json   ← 阶段模块间传递

对象通信 (注册表):
  reports/devops-registry.json          ← 所有对象的唯一真相源
  reports/devops-registry-diff.json     ← 本次刷新变更 diff

事件通信 (EventStore):
  reports/devops-events.jsonl           ← 不可变事件流 (append-only)
```

### 八、统一管理 CLI

```bash
# 对象发现与注册
devops registry refresh              # 重新发现所有对象
devops registry list                 # 列出所有已注册对象
devops registry list --type app      # 按类型过滤
devops registry get app:converge     # 查看单个对象详情
devops registry health               # 所有对象健康状态

# 阶段执行
devops pipeline run                  # 执行完整流水线
devops pipeline run --from test      # 从指定阶段开始

# 六可验证
devops verify --capability all       # 全六可验证
devops verify --capability observability
```

### 九、全局禁止模式

| ❌ 禁止 | ✅ 正确 |
|------|------|
| 一个脚本做所有 DevOps 事 | 22 个独立模块 |
| 对象信息散落在各处 | 统一 registry.json |
| 手动添加对象到注册表 | 自动发现→自动注册 |
| 模块间直接 SSH/curl | 通过 JSON 接口通信 |
| 阶段模块内包含六可检查 | 六可=独立横切模块 |
| 对象消失直接删除记录 | 标记 `health: "gone"` 保留审计 |

### 十、实施优先级

```
Phase 1 (本周): 对象系统 + 核心阶段
  D0R(Registry) + D02(Code) + D03(Build) + D06(Deploy) + D07(Operate)

Phase 2 (下周): 质量保障
  D04(Test) + D05(Release) + DO1-DO6(六可横切)

Phase 3 (月度): 全自动
  D01(Plan) + D08(Monitor) + CI/CD 集成
```

## Output

- 22 个模块文件 (.claude/rules/devops-*.md)
- 9 个 JSON Schema (.claude/schemas/devops-*.json)
- 对象注册表 (reports/devops-registry.json)
- 发现脚本 (scripts/devops/discover.sh)
- 管理 CLI (scripts/devops/registry.sh)
- 流水线编排 (scripts/devops/pipeline.sh)

## Interface Contract

- **消费者**: 所有 D01-D08 + DO1-DO6 + D0R + D0S 模块
- **依赖**: `00-architecture-meta.md` (架构四原则)
- **约定**: 模块间零共享状态；JSON-only 通信；对象自动发现自动注册；消失=标记不删除
