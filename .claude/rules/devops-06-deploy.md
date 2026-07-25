# D06 — Deploy 阶段：生产部署 + 健康检查 + 资源限制

> 层: L2 执行层 | 版本: v1.0
> 单一职责: 生产部署 + 部署后健康验证 + 资源上限设置 + 自愈触发
> 依赖: `devops-00-architecture.md` · `devops-0r-registry.md`

## Trigger

上游 D05(Release) 通过 → `reports/devops-release-result.json` `.pass = true` → 自动触发
或手动: `git push dokku main` 后自动执行本模块验证

## Input

- `reports/devops-release-result.json` (上游，含 staging 冒烟结果)
- `reports/devops-registry.json` (对象注册表)
- Dokku app 状态 (`dokku ps:report`)

## Action

### Step 1: 资源限制设置 (防雪崩)

```bash
# 设置内存上限
dokku resource:set payment-router memory 256M
dokku resource:set converge memory 512M

# 验证
dokku resource:report <app> | grep "Memory limit"
```

| App | 内存上限 | CPU | 原因 |
|------|:---:|:---:|------|
| converge | 512M | 1.0 | Nginx + PHP-FPM + 多模块 |
| payment-router | 256M | 0.5 | PHP built-in server 单进程 |
| adscope | 512M | 1.0 | Python uvicorn + scraper |

### Step 2: 健康检查配置 (自愈触发)

```bash
# 配置 Dokku 健康检查
dokku checks:set <app> web --type http --path /health --timeout 10 --attempts 3

# 或通过 app.json (项目根目录)
```

```json
{
  "healthchecks": {
    "web": [
      {
        "type": "http",
        "path": "/health",
        "timeout": 10,
        "attempts": 3,
        "initial_delay": 5
      }
    ]
  }
}
```

### Step 3: 部署后六步验证 (来自 13-distributed-verification.md)

```
① 进程检查: docker ps | grep <app>          → PID 存在
② 网络检查: curl -sk https://<domain>/      → HTTP 200
③ 路由检查: docker logs | grep "GET /"      → 请求到达
④ 参数检查: docker logs | grep -i "error"   → 零解析错误
⑤ 业务检查: 数据库连接正常                    → affected rows > 0
⑥ 持久检查: 数据库时间戳在 60s 内             → 最新记录
```

**任一步骤失败 → 自动 `dokku ps:rollback <app>` → 回滚到上一版本**

### Step 4: 写入 registry

```
更新对象:
  app:<name>           → health="ok", status="deployed"
  resourcelimit:<name> → status="active", props={memory:"256M", cpu:"0.5"}
  healthcheck:<name>   → status="active"
  deployment:<name>:<commit> → 新增, status="active"
```

## Output

```json
{
  "stage": "deploy",
  "pass": true,
  "app": "payment-router",
  "version": "abc1234",
  "checks": {
    "process": true,
    "network": true,
    "route": true,
    "params": true,
    "business": true,
    "persistence": true
  },
  "resource_limits": {
    "memory": "256M",
    "cpu": "0.5"
  },
  "healthcheck": {
    "path": "/health",
    "interval": 30,
    "status": "configured"
  },
  "timestamp": "2026-07-25T12:00:00Z"
}
```

## Managed Objects

| 对象类型 | 操作 | 说明 |
|------|------|------|
| app | update | 更新部署状态 + health |
| resourcelimit | create/update | 设置内存/CPU 上限 |
| healthcheck | create/update | 配置健康检查端点 |
| deployment | create | 记录本次部署 |

## Six-Capability Gates

| 六可 | 检查项 | 通过标准 |
|------|------|------|
| 🔭 可观察 | 六步验证每步有明确输出 | 6/6 步骤有状态 |
| 📋 可追溯 | 部署事件写入 EventStore | 含 commit+timestamp+deployer |
| 📐 可审计 | 资源限制不可跳过 | memory != "unlimited" |
| ✅ 可验证 | 健康检查端点返回 200 | `curl /health` → HTTP 200 |
| 🧬 可进化 | 新检查步骤只需加 verify 函数 | 不改验证主流程 |
| 🩹 可自愈 | 任一检查失败 → 自动 rollback | 30s 内完成回滚 |

## Interface Contract

- **调用方**: D05(Release) — 读取 `release-result.json`
- **被调用方**: D07(Operate) — 输出 `deploy-result.json`
- **读取**: `reports/devops-registry.json` (app + resourcelimit + healthcheck 对象)
- **输出**: `reports/devops-deploy-result.json`
- **约定**: 六步验证全通过 → pass → 下游可执行；任一步骤失败 → 自动 rollback → fail
