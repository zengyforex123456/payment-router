---
name: converge-docker-local-then-deploy
description: Converge Docker 标准流程：本地测试通过 → 推送镜像 → 服务器一键部署
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 262cd0a8-3c8b-477c-afc4-8d9a8a2cb5d8
---

# Converge Docker 部署标准流程

> **Why**: SCP/git pull 依赖服务器 vendor/ 目录，本地与服务器 PHP/扩展版本差异导致 500。
> Docker 镜像将代码+依赖+PHP运行时打包为不可变单元，本地测过=线上没问题。

## 标准流程

```
本地 Docker 测试              远程服务器部署
─────────────────          ─────────────────
① docker build             ④ docker compose pull
② docker compose up -d     ⑤ docker compose up -d
③ curl health + 页面验证    ⑥ 健康检查 + 旧镜像清理
   ↓ 全部 200                   ↓
   推送代码 + 镜像              完成
```

## 本地测试命令

```bash
cd D:\project\zhice-os

# 方法 1: 使用已有的 docker-compose.dev.yml (Volume 挂载，实时同步)
cd projects/converge/data/source
docker compose -f docker-compose.dev.yml up -d
# 验证:
curl -sf http://localhost:8080/health
curl -sf http://localhost:8080/login-v2.php | head -20

# 方法 2: 用新的 Dockerfile.prod 构建完整镜像（测试生产环境）
cd D:\project\zhice-os
docker build \
  -f projects/converge/data/source/Dockerfile.prod \
  -t converge:test \
  projects/
# 启动测试:
docker run -d --name converge-test -p 8080:80 \
  -e DB_HOST=host.docker.internal -e DB_NAME=converge \
  -e DB_USER=root -e DB_PASSWORD=root \
  -e REDIS_HOST=host.docker.internal \
  converge:test
# 验证:
curl -sf http://localhost:8080/health
# 清理:
docker rm -f converge-test
```

## 服务器部署命令

```bash
# SSH 到服务器，进入 converge 目录
ssh root@137.184.225.93
cd /var/www/converge

# 一键部署
bash scripts/deploy.sh

# 回滚
bash scripts/deploy.sh --rollback
```

## 首次部署需要

1. **GitHub Packages 权限**: 仓库 Settings → Actions → Workflow permissions → Read and write packages
2. **服务器 ghcr 认证**: `docker login ghcr.io -u zengyforex123456`（创建 GitHub Personal Access Token，权限 `read:packages`）
3. **首次触发**: push master 或手动触发 Actions → Converge Docker Build & Push → Run workflow

## 文件清单

| 文件 | 作用 |
|------|------|
| `Dockerfile.prod` | 多阶段构建: Stage1 composer install → Stage2 生产运行时 |
| `docker-compose.server.yml` | 服务器编排: `image: ghcr.io/zengyforex123456/converge:latest` |
| `.github/workflows/converge-docker.yml` | CI/CD: push master 自动 build + push 到 GHCR |
| `scripts/deploy.sh` | 服务器端: pull → up -d → health check → prune |
| `docker-compose.dev.yml` | 本地开发: Volume 挂载实时同步 + converge-core 路径映射 |

## 关键原则

- **本地先测再部署** — Docker build 后先在本地验证 health + 关键页面
- **镜像即真相源** — 服务器只是 pull + up，绝不在服务器上改代码
- **回滚一步到位** — `deploy.sh --rollback` 秒切上一个镜像 tag
