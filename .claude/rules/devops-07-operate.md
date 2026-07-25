# D07 — Operate 阶段：备份 + 回滚 + 运行维护

> 层: L2 执行层 | 版本: v1.0
> 单一职责: 数据库自动备份 + 回滚执行 + 运行状态维护
> 依赖: `devops-00-architecture.md` · `devops-0r-registry.md` · `devops-06-deploy.md`

## Trigger

- 定时: 每日凌晨 2:00 (cron) → 自动备份
- 事件: D06(Deploy) 健康检查失败 → 自动回滚
- 手动: `devops pipeline run --from operate` / `devops operate backup`

## Input

- `reports/devops-deploy-result.json` (上游部署结果)
- `reports/devops-registry.json` (对象注册表)
- 服务器时间 (cron 触发)

## Action

### Step 1: 自动备份 (每日 2:00)

```bash
#!/bin/bash
# 部署到服务器: /root/backup-mysql.sh
BACKUP_DIR="/root/backups"
RETENTION_DAYS=7
DATE=$(date +%Y%m%d_%H%M)

mkdir -p "$BACKUP_DIR"

# 备份所有 Dokku MySQL 数据库
for db in $(dokku mysql:list | tail -n +2); do
    # 获取连接信息
    eval $(dokku mysql:info $db --dsn 2>/dev/null | grep -oP '(?<=mysql://)[^ ]+')
    
    mysqldump -h "$HOST" -u "$USER" -p"$PASSWORD" "$DB" \
        --single-transaction --routines --triggers \
        | gzip > "$BACKUP_DIR/backup-${db}-${DATE}.sql.gz"
    
    echo "✅ $db → backup-${db}-${DATE}.sql.gz ($(du -h $BACKUP_DIR/backup-${db}-${DATE}.sql.gz | cut -f1))"
done

# 清理 >7 天的备份
find "$BACKUP_DIR" -name "backup-*.sql.gz" -mtime +$RETENTION_DAYS -delete
echo "🧹 Cleaned backups older than ${RETENTION_DAYS} days"
```

**备份验证** (每月自动还原测试):
```bash
# 随机抽一个备份，还原到临时库，跑 count 对比
```

### Step 2: 回滚执行

```bash
# 回滚到上一版本
dokku ps:rollback <app>

# 或回滚到指定 commit
dokku git:sync <app> --build <commit-sha>

# 验证回滚成功
dokku ps:report <app> | grep "Deployed: true"
curl -sk https://<domain>/health | grep "200"
```

**回滚决策矩阵**:
| 触发条件 | 回滚方式 | RTO 目标 |
|------|------|:---:|
| 健康检查连续 3 次失败 | 自动 `ps:rollback` | <30s |
| 错误率 >5% (5分钟窗口) | 告警 → 人工确认 → rollback | <3min |
| 数据库迁移失败 | 手动回滚 SQL + 代码 | <5min |
| 安全漏洞 (高危) | 立即 rollback → 锁部署 | <1min |

### Step 3: 磁盘空间监控

```bash
# 检查磁盘使用率
DISK_USAGE=$(df / | tail -1 | awk '{print $5}' | tr -d '%')
if [ "$DISK_USAGE" -gt 80 ]; then
    echo "⚠️  Disk usage: ${DISK_USAGE}%"
    # 清理 Docker
    docker system prune -f --filter "until=24h"
fi
```

### Step 4: 写入 registry

```
更新对象:
  backup:<filename>  → 新增, status="active"
  旧 backup 对象     → health="expired" (>7 天) 或删除
  deployment:<app>:<old_commit> → status="rolled_back"
```

## Output

```json
{
  "stage": "operate",
  "pass": true,
  "operation": "backup",
  "backups": [
    {"db": "payment-db", "file": "backup-payment-db-20260725_0200.sql.gz", "size": "1.2M"}
  ],
  "cleaned": 3,
  "disk_usage_pct": 45,
  "timestamp": "2026-07-25T02:00:00Z"
}
```

## Managed Objects

| 对象类型 | 操作 | 说明 |
|------|------|------|
| backup | create | 每次备份新建对象 |
| backup | update | >7 天标记 expired |
| deployment | update | 回滚时标记 rolled_back |
| alert | update | 磁盘 >80% 创建告警 |

## Six-Capability Gates

| 六可 | 检查项 | 通过标准 |
|------|------|------|
| 🔭 可观察 | 备份状态可见 | 每次备份有日志 + registry 记录 |
| 📋 可追溯 | 备份文件含时间戳 | `backup-{db}-{YYYYMMDD_HHMM}.sql.gz` |
| 📐 可审计 | 回滚操作有记录 | EventStore 记录谁+何时+为何回滚 |
| ✅ 可验证 | 备份可恢复 | 每月自动还原测试 |
| 🧬 可进化 | 新数据库只需加一行 | dokku mysql:list 自动遍历 |
| 🩹 可自愈 | 磁盘 >80% 自动清理 | prune + 告警 |

## Interface Contract

- **调用方**: D06(Deploy) — 读取 `deploy-result.json`；cron — 定时触发
- **被调用方**: D08(Monitor) — 输出 `operate-result.json`
- **读取**: `reports/devops-registry.json` (database + backup 对象)
- **输出**: `reports/devops-operate-result.json`
- **约定**: 备份失败 → 告警但不阻塞；回滚失败 → 升级告警 + 人工介入
