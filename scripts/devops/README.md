# Converge DevOps 工具链 — 复用指南

> 版本: v1.0 | 测试: 3 项目 (converge, payment-router, adscope)
> 目录: `E:\project\converge-skeleton\scripts\devops\`

## 新项目接入 (4 步)

### Step 1: 创建 .deploy.json

```bash
cd /e/project/<新项目>/
```

```json
{
  "name": "my-new-app",
  "type": "python-fastapi|php-standalone|node-express",
  "domain": "my-app.vip",
  "services": [{ "name": "api", "dockerfile": "docker/Dockerfile", "internal_port": 5000, "health": "/health" }],
  "database": { "name": "my_app_db", "migrations_dir": "database/migrations" },
  "secrets": ["DB_PASSWORD", "API_KEY"],
  "env_file": ".env.production",
  "deployed": false
}
```

### Step 2: 创建 .env.vars.json

```bash
# 统一环境变量管理 — 单一真相源
node -e "console.log(JSON.stringify({
  app_name: 'my-new-app',
  vars: { APP_ENV: 'production', APP_DEBUG: 'false' },
  sensitive: ['DB_PASSWORD', 'API_KEY'],
  env_source: '.env.production',
  deployed: false
}, null, 2))" > .env.vars.json
```

### Step 3: 运行部署前检查

```bash
cd /e/project/converge-skeleton/
bash scripts/devops/pre-deploy-check.sh /e/project/<新项目>/
```

检查 6 类陷阱：
- [ ] Dockerfile EXPOSE = 5000 (非 8080!)
- [ ] Procfile 用 `$PORT` 变量
- [ ] .deploy.json JSON 有效
- [ ] .env.vars.json 有 app_name
- [ ] Dockerfile 无硬编码密钥
- [ ] entrypoint 用 mysqladmin ping (非 PHP mysqli)

### Step 4: 部署 + 注入环境变量

```bash
# 1. 同步环境变量到 Dokku
cd /e/project/converge-skeleton/
bash scripts/devops/sync-env.sh <新项目名>

# 2. Git push 到 Dokku
cd /e/project/<新项目>/
git remote add dokku dokku@137.184.225.93:<app-name>
git push dokku main

# 3. 设置资源限制 (防止 OOM)
ssh root@137.184.225.93
dokku resource:set <app> memory 256M
dokku checks:set <app> web --type http --path /health --timeout 10
```

---

## 工具速查

| 工具 | 运行位置 | 命令 | 作用 |
|------|:---:|------|------|
| **pre-deploy-check.sh** | 本地 | `bash pre-deploy-check.sh ../new-project/` | 阻断 Dockerfile EXPOSE 8080 等 6 类陷阱 |
| **sync-env.sh** | 本地→远程 | `bash sync-env.sh new-project` | 读取 .env.vars.json → 安全注入 Dokku |
| **kag-db.js** | 本地 | `node kag-db.js search "mysql"` | 查询知识库 (过去踩过的坑) |
| **knowledge-capture.js** | 本地 | `node knowledge-capture.js entries.json` | Memory(.md) + KAG(JSONL) 双写知识 |
| **_server-inject.py** | 服务器 | `python3 /root/_server-inject.py <app> <env-file> KEY1 KEY2` | 安全注入密钥 (值不经过 shell) |
| **verify-six-capabilities.sh** | 本地→远程 | `bash verify-six-capabilities.sh` | 🔭📋📐✅🧬🩹 六可验证 |

---

## 知识库查询

```bash
cd /e/project/converge-skeleton/

# 搜索已有错误模式
node scripts/devops/kag-db.js search "dokku"
node scripts/devops/kag-db.js search "mysql"
node scripts/devops/kag-db.js search "tls"

# 列出最近 10 条知识
node scripts/devops/kag-db.js list 10

# 查看统计
node scripts/devops/kag-db.js stats
```

### 当前知识库 3 条：

| ID | 标题 | 检测模式 | 成熟度 |
|:---:|------|------|:---:|
| 1 | EXPOSE 端口不匹配 | `EXPOSE 8080` → nginx `listen 8080` | 已验证 |
| 2 | MySQL TLS 自签名证书 | `ERROR 2026` + mysqladmin ping | 已验证 |
| 3 | proxy:disable 清除域名 | disable→enable→domains 丢失 | 已验证 |

---

## 复用保证

- **新项目**: 只需 `.deploy.json` + `.env.vars.json` 两个文件
- **pre-deploy-check.sh**: 自动阻挡已知陷阱，新增陷阱只需加检测规则
- **kag-db.js**: 每次踩坑自动写入，后续项目搜索即可避免
- **sync-env.sh**: 读完 .env.vars.json 自动注入，零手动

## 当前待处理

1. converge entrypoint `--ssl-mode=DISABLED` 推送 (已 commit, 等 push)
2. adscope 密钥注入 + 首次部署
3. payment-router Dockerfile EXPOSE 5000 修复
