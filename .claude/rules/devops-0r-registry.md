# D0R — DevOps 对象注册表

> 层: L1.5 对象注册表层 | 版本: v1.0
> 职责: 自动发现所有 DevOps 对象 → 统一注册 → 统一查询。**所有对象的唯一真相源。**
> 依赖: `devops-00-architecture.md` (对象类型定义)

## Trigger

`devops registry refresh` / 部署前检查 / 定时 cron / 对象变更事件

## Input

- 服务器连接 (`HOSTS` 配置文件或 `pipeline-state.json`)
- 本地项目目录 (`E:\project\*\\.deploy.json`)

## Action

### Step 1: 自动发现 (Discovery)

每种对象类型有独立发现函数，互不依赖：

```bash
# project — 本地扫描 .deploy.json
discover_projects() {
    for d in /e/project/*/; do
        [ -f "$d.deploy.json" ] || continue
        name=$(json_field "$d.deploy.json" "name")
        type=$(json_field "$d.deploy.json" "type")
        domain=$(json_field "$d.deploy.json" "domain")
        echo "{\"id\":\"$name\",\"type\":\"project\",\"props\":{\"type\":\"$type\",\"domain\":\"$domain\"}}"
    done
}

# app — 远程 Dokku 应用列表
discover_apps() {
    ssh "$HOST" "dokku apps:list" | tail -n +2 | while read app; do
        domain=$(ssh "$HOST" "dokku domains:report $app --dokku-domains-simple")
        echo "{\"id\":\"$app\",\"type\":\"app\",\"props\":{\"domain\":\"$domain\"}}"
    done
}

# database — 远程 Dokku MySQL 列表
discover_databases() {
    ssh "$HOST" "dokku mysql:list" | tail -n +2 | while read db; do
        linked=$(ssh "$HOST" "dokku mysql:info $db --links")
        echo "{\"id\":\"$db\",\"type\":\"database\",\"props\":{\"linked_apps\":\"$linked\"}}"
    done
}

# secret — 远程 Dokku 配置 (key 列表，value 脱敏)
discover_secrets() {
    ssh "$HOST" "dokku apps:list" | tail -n +2 | while read app; do
        ssh "$HOST" "dokku config:export $app --format shell" 2>/dev/null | while read line; do
            key=$(echo "$line" | cut -d= -f1)
            echo "{\"id\":\"$app:$key\",\"type\":\"secret\",\"props\":{\"app\":\"$app\",\"key\":\"$key\"}}"
        done
    done
}

# deployment — git report
discover_deployments() { ... }

# backup — 扫描 /root/backups/
discover_backups() { ... }

# healthcheck — 从 .deploy.json 读取
discover_healthchecks() { ... }

# resourcelimit — dokku resource:report
discover_resourcelimits() { ... }

# alert — 从 devops-alerts.json 读取
discover_alerts() { ... }
```

### Step 2: 注册 (Registration)

```
新发现对象 vs 已注册对象 → diff
  ├─ NEW: 注册 + EventStore 记录 "object.created"
  ├─ CHANGED: 更新 props + EventStore 记录 "object.updated" (含 old_props)
  ├─ GONE: 标记 health="gone" + EventStore 记录 "object.removed"
  └─ SAME: 更新 discovered_at 时间戳
```

### Step 3: 查询 (Query)

```bash
devops registry list              # 所有对象表格
devops registry list --type app   # 按类型过滤
devops registry get app:converge  # 单个对象 JSON
devops registry health            # 健康状态汇总
devops registry diff              # 上次刷新以来的变更
```

## Output

- `reports/devops-registry.json` — 所有已注册对象 (唯一真相源)
- `reports/devops-registry-diff.json` — 本次刷新 diff
- `reports/devops-events.jsonl` — 对象变更事件 (append-only)

### registry.json Schema

```json
{
  "version": "1.0",
  "host": "137.184.225.93",
  "refreshed_at": "2026-07-25T12:00:00Z",
  "objects": {
    "project:converge-skeleton": {
      "type": "project",
      "status": "active",
      "props": {
        "domain": "paymentrouter.vip",
        "project_type": "php-standalone",
        "services": ["payment-router"],
        "database": "payment_router",
        "secret_count": 4
      },
      "discovered_at": "2026-07-25T12:00:00Z",
      "health": "ok"
    },
    "app:payment-router": {
      "type": "app",
      "status": "active",
      "props": {
        "domain": "paymentrouter.vip",
        "deploy_branch": "main",
        "dokku_app": "payment-router"
      },
      "parent": "project:converge-skeleton",
      "discovered_at": "2026-07-25T12:00:00Z",
      "health": "ok"
    }
  },
  "summary": {
    "total": 15,
    "by_type": { "project": 4, "app": 3, "database": 3, "secret": 12, "backup": 6 },
    "by_health": { "ok": 20, "gone": 0, "error": 0 }
  }
}
```

## Managed Objects

| 对象类型 | 操作 | 发现方式 | 发现位置 |
|------|------|------|------|
| host | read | 配置文件 | `HOSTS` 文件 |
| project | read | 扫描 `.deploy.json` | 本地 `E:\project\*\` |
| app | read | `dokku apps:list` | 远程 SSH |
| database | read | `dokku mysql:list` | 远程 SSH |
| secret | read (脱敏) | `dokku config:export` | 远程 SSH |
| deployment | read | `dokku git:report` | 远程 SSH |
| backup | read | `ls /root/backups/` | 远程 SSH |
| healthcheck | read | 读取 `.deploy.json` | 本地 |
| resourcelimit | read | `dokku resource:report` | 远程 SSH |
| alert | create/update/delete | 配置文件 | 本地 `devops-alerts.json` |

## Six-Capability Gates

| 六可 | 检查项 | 通过标准 |
|------|------|------|
| 🔭 可观察 | 所有对象有 health 字段 | 100% 覆盖 |
| 📋 可追溯 | 对象变更写入 EventStore | 每次 refresh 有 diff 事件 |
| 📐 可审计 | 对象消失标记 gone 不删除 | gone 对象保留 ≥30 天 |
| ✅ 可验证 | 对象 props 与实际状态一致 | diff 为空 (全匹配) |
| 🧬 可进化 | 新对象类型只需加 discover 函数 | 不改 registry 核心逻辑 |
| 🩹 可自愈 | 对象 health=error 触发告警 | error 对象自动创建 alert |

## Interface Contract

- **消费者**: D01-D08 (所有阶段模块读取 registry 获取对象状态)
- **被调用方**: `discover.sh` (发现脚本), `registry.sh` (管理 CLI)
- **输出**: `reports/devops-registry.json` (注册表), `reports/devops-events.jsonl` (事件流)
- **约定**: registry.json 是唯一真相源；对象只标记不删除；每次 refresh 写 diff
