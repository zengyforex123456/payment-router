---
name: docker-production-data-safety
description: Docker 生产级数据安全 — Volume 持久化·3-2-1 备份·迁移容器·自愈三层·密钥管理
metadata: 
  node_type: memory
  type: reference
  originSessionId: c898435f-e7cd-482c-9fbb-6adbb847449c
---

# Docker 生产级数据安全 — 全方案

> 来源: websearch 2026-07 + Converge 实战验证

## 一、数据持久化 (防容器崩溃丢数据)

```
容器 = 无状态 (随时销毁重建)
数据 = Docker Volume 或 Bind Mount (独立于容器生命周期)

docker compose down        → 容器删除, Volume 保留 ✅
docker compose down -v     → 容器 + Volume 全部删除 ⚠️ (危险!)
```

**Converge 已配置**: mysql_data、redis_data、app_storage 三个 named volume。

## 二、3-2-1 备份法则

| 副本 | 介质 | Converge 实现 |
|------|------|------|
| 3 份 | 全量 | 每日 mysqldump + Volume tar |
| 2 种 | 介质 | 本地磁盘 + S3/OSS 异地 |
| 1 份 | 异地 | rclone → S3 (cron 每周) |

**Converge 备份**: `scripts/backup-db.sh` — mysqldump `--single-transaction` (不锁表) + gzip + 7 天保留。

## 三、Schema 迁移 (防空库启动)

**反模式**: 在 App 启动时跑迁移 → 多副本竞争 + 滚动更新冲突

**正确**: 专用 migrator 容器，一次性执行：
```yaml
migrator:
  depends_on: {mysql: {condition: service_healthy}}
  restart: "no"  # 跑完就退出
app:
  depends_on: {migrator: {condition: service_completed_successfully}}
```

## 四、自愈三层 (防运行时故障)

| 层 | 机制 | 恢复时间 | 覆盖场景 |
|---|------|:---:|------|
| L0 | Docker healthcheck + restart:unless-stopped | 2-5s | 进程崩溃/OOM |
| L1 | auto-heal.sh cron 5min → docker compose up -d --build | 30s-5min | 配置错误/依赖缺失 |
| L2 | git reset HEAD~1 + rebuild (回滚) | 2-5min | 代码 bug/迁移失败 |

## 五、密钥管理

| ❌ 禁止 | ✅ 正确 |
|--------|--------|
| Dockerfile 硬编码密码 | `${DB_PASSWORD:-default}` 环境变量 |
| .env 提交 Git | .env 在 .gitignore，用 .env.example |
| build-arg 传密钥 (留在镜像层) | Docker Secrets / Vault |
| docker inspect 暴露密码 | `--secret` 挂载到 `/run/secrets/` |

## 六、生产检查清单

```
□ named volume 持久化所有有状态数据 (mysql/redis/storage)
□ 每日自动备份 + 7 天保留 + 异地同步
□ 专用 migrator 容器 (非 App 启动时迁移)
□ Docker healthcheck + restart:unless-stopped
□ 资源限制 (CPU/Memory) 防 OOM
□ 日志轮转 (max-size + max-file)
□ 非 root 用户运行容器
□ 密钥不嵌入镜像层
□ 定期恢复演练 (每季度)
□ docker-compose.server.yml 与 docker-compose.yml 分离 (dev/prod)
```

**关联**: [[docker-schema-not-initialized]] [[converge-p0-p2-security-patches]] [[converge-deployment]]
