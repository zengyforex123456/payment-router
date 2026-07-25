# D03 — Build 阶段：Docker 构建 + 密钥注入

> 层: L2 执行层 | 版本: v1.0
> 单一职责: 构建 Docker 镜像，确保密钥零明文
> 依赖: `devops-00-architecture.md` · `devops-0r-registry.md`

## Trigger

上游 D02(Code) 通过 → `reports/devops-code-result.json` `.pass = true` → 自动触发
或手动: `devops pipeline run --from build`

## Input

- `reports/devops-code-result.json` (上游，含 commit SHA)
- `reports/devops-registry.json` (对象注册表，读 project + secret 对象)
- 项目源代码 (本地目录)

## Action

### Step 1: 密钥扫描 — 检测明文密码

```
扫描范围: Dockerfile, .env, docker-compose.yml, entrypoint.sh, *.php, *.py
检测模式:
  - password\s*=\s*['"][^'"]{4,}['"]       (赋值)
  - DB_PASSWORD=\S+                         (env 文件)
  - MYSQL_ROOT_PASSWORD=\S+                (MySQL)
  - STRIPE_SECRET_KEY=sk_live_             (Stripe 活 key)
  - PADDLE_API_KEY=                        (Paddle)
  - OPENAI_API_KEY=sk-                     (OpenAI)
  - APP_SECRET=                            (应用密钥)
  - JWT_SECRET=                            (JWT)
  - ENCRYPTION_KEY=                        (加密)
```

### Step 2: 迁移密钥到 Dokku

```bash
# 对每个项目, 从 .env 文件提取密钥 → dokku config:set
dokku config:set <app> DB_PASSWORD=<value>
dokku config:set <app> STRIPE_SECRET_KEY=<value>
# ...

# 验证: 确认 Dockerfile/entrypoint 中无硬编码密钥
grep -r "DB_PASSWORD\|STRIPE_KEY\|API_KEY" docker/ && echo "❌ 发现明文" || echo "✅"
```

### Step 3: 写入 registry

```
更新 secret 对象:
  secret:<app>:DB_PASSWORD → status="injected", health="ok"
```

### Step 4: EventStore 记录

```json
{
  "type": "secret.injected",
  "app": "payment-router",
  "keys": ["DB_PASSWORD", "STRIPE_SECRET_KEY", "PADDLE_API_KEY", "APP_SECRET"],
  "timestamp": "2026-07-25T12:00:00Z"
}
```

## Output

```json
{
  "stage": "build",
  "pass": true,
  "app": "payment-router",
  "image": "dokku/payment-router:latest",
  "secrets": {
    "total": 4,
    "injected": 4,
    "hardcoded": 0
  },
  "warnings": [],
  "timestamp": "2026-07-25T12:00:00Z"
}
```

## Managed Objects

| 对象类型 | 操作 | 说明 |
|------|------|------|
| secret | create/update | 提取本地 .env → `dokku config:set` |
| deployment | create | 记录构建事件 |

## Six-Capability Gates

| 六可 | 检查项 | 通过标准 |
|------|------|------|
| 🔭 可观察 | 构建日志可查看 | `docker logs` 可追溯 |
| 📋 可追溯 | 密钥注入有 EventStore 记录 | 每条 secret 有 injected 事件 |
| 📐 可审计 | 硬编码密钥扫描阻断 | 0 明文发现 |
| ✅ 可验证 | `dokku config:get` 确认密钥已设置 | 所有密钥有值 (脱敏) |
| 🧬 可进化 | 新密钥类型只需加检测模式 | 不改扫描逻辑主体 |
| 🩹 可自愈 | 发现明文密钥自动迁移 | 自动 `config:set` + 清理文件 |

## Interface Contract

- **调用方**: D02(Code) — 读取 `code-result.json`
- **被调用方**: D04(Test) — 输出 `build-result.json`
- **读取**: `reports/devops-registry.json` (project + secret 对象)
- **输出**: `reports/devops-build-result.json`
- **约定**: 0 明文密钥 → pass；任一硬编码 → fail + 阻断下游
