---
name: docker-schema-not-initialized
description: Docker 部署 MySQL 空库→App 查询缺表→500，3 层防御 + 知识积累
metadata: 
  node_type: memory
  type: feedback
  originSessionId: c898435f-e7cd-482c-9fbb-6adbb847449c
---

# Docker Schema 未初始化 — 检测·预防·自愈

**检测模式**: `Table 'xxx.xxx' doesn't exist` + Docker 新部署/重建卷后首次启动

**根因**: Docker `depends_on: mysql {condition: service_healthy}` 只验证 MySQL 进程存活（mysqladmin ping），不验证 schema 是否存在。`MYSQL_DATABASE` 环境变量创建空库，但 MigrationRunner 从未被自动调用。

**因果链**:
1. Docker volume 为空或新创建 → MySQL 空库
2. MySQL health check = mysqladmin ping → 通过（进程存活）
3. App 连接成功 → 查询 `settings` 等关键表 → Table doesn't exist → 500
4. MigrationRunner 存在但无人调用（手动工具，无自动触发）

**修复 (3 层防御)**:

| 层 | 方案 | 文件 |
|---|------|------|
| L1 Docker | migrator init 容器，`depends_on mysql healthy` → 跑 `run-migrations.php` → App 等 migrator 成功 | `docker-compose.server.yml` |
| L1 Docker | Dockerfile ENTRYPOINT 跑迁移后再启动 supervisor（standalone 模式） | `scripts/docker-entrypoint.sh` + `Dockerfile` |
| L2 Health | HealthChecker 验证 5 张关键表存在（settings/users/campaigns/clicks/migrations），不只 ping | `src/Observability/HealthChecker.php` |
| L3 Backup | 每日 mysqldump + 7 天保留 + 异地 rclone | `scripts/backup-db.sh` |

**验证**:
```bash
# 1. 干净环境测试
docker compose -f docker-compose.server.yml down -v
docker compose -f docker-compose.server.yml up -d
# migrator 必须先成功 → app 才启动

# 2. Health check 验证
curl http://localhost/health | jq '.checks.database'
# → ok: true, applied_migrations: "up-to-date"
# 缺表时 → ok: false, missing_tables: ["settings","users",...]

# 3. 备份恢复测试
sh scripts/backup-db.sh  # 生成 backup_*.sql.gz
# 模拟灾难恢复:
docker compose down -v
docker compose up -d mysql
gunzip < storage/backups/backup_xxx.sql.gz | docker exec -i source-mysql-1 mysql -u root -p$$DB_PASS converge
docker compose up -d
```

**关联**: [[converge-p0-p2-security-patches]] [[sidebar-four-bugs-blind-spot]]
