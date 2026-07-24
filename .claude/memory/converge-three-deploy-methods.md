---
name: converge-three-deploy-methods
description: Converge 三种部署方案 + Docker 镜像为推荐方案
metadata: 
  node_type: memory
  type: reference
  originSessionId: 262cd0a8-3c8b-477c-afc4-8d9a8a2cb5d8
---

# Converge 部署方案（2026-07-16 更新）

## 推荐方案：Docker 镜像部署 ✅

```bash
# 服务器上一键部署
ssh root@137.184.225.93 "cd /var/www/converge && bash scripts/deploy.sh"
```

**原理**：GitHub Actions 自动构建 Docker 镜像 → 推送到 ghcr.io → 服务器 pull + restart

**文件**:
- `Dockerfile.prod` — 多阶段构建（Stage 1: composer install, Stage 2: 生产运行时）
- `.github/workflows/converge-docker.yml` — CI/CD 自动构建+推送
- `docker-compose.server.yml` — 使用 `image: ghcr.io/zengyforex123456/converge:latest`
- `scripts/deploy.sh` — 服务器端部署脚本（含 rollback）

**优势**: 依赖在 build 时解决，本地/服务器完全一致，秒级回滚

## 方案 2: Git Pull（备选）

```bash
ssh root@137.184.225.93 "cd /var/www/converge && git pull origin main && docker compose restart app"
```

**前提**: converge 代码已推送到 `github.com/zengyforex123456/converge`

## 方案 3: SCP 直推（紧急救火）

```bash
cd D:/project/zhice-os/projects/converge/data/source
bash scripts/scp-deploy.sh root@137.184.225.93 /var/www/converge
```

**注意**: 跳过 vendor/，需要额外运行 `composer dump-autoload`

## 仓库拓扑

```
zhice-os monorepo (github.com/zengyforex123456/zhice-os) — master
├── .github/workflows/converge-docker.yml  ← CI 触发
├── projects/converge/      ← 开发和构建源
└── projects/converge-core/ ← 构建依赖（path repo）

staging 服务器 (137.184.225.93)
└── /var/www/converge
    ├── docker-compose.server.yml  ← image 模式
    └── scripts/deploy.sh          ← docker compose pull + up
```

## 决策树

```
改了什么？
├─ Converge PHP → 方案 1 (git push → CI build → server deploy.sh)
├─ 紧急热修复  → 方案 3 (SCP)
└─ zhice-os Node.js 核心 → zhice-os 自己的 docker-deploy.yml
```
