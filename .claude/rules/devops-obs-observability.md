# DO1 — 🔭 可观察 (Observability)

> 层: L4 横切层 | 版本: v1.0
> 单一职责: 验证所有 DevOps 对象的状态可被外部感知
> 依赖: `devops-00-architecture.md` · `devops-0r-registry.md`

## Trigger

每个阶段完成后自动触发 / `devops verify --capability observability`

## Input

- `reports/devops-registry.json` (所有对象)
- `reports/devops-{stage}-result.json` (阶段结果)

## Action

### 验证规则

| 对象类型 | 可观察检查 | 通过标准 |
|------|------|------|
| host | CPU/内存/磁盘有数值 | 3 项指标非空 |
| project | `.deploy.json` 可读 | 文件存在 + JSON 有效 |
| app | `dokku ps:report` 可读 | Deployed=true |
| database | `dokku mysql:info` 可读 | 连接信息完整 |
| secret | key 列表可见 (value 脱敏) | 所有 key 有记录 |
| deployment | commit + timestamp 可见 | 2 项非空 |
| backup | 文件存在 + 大小可见 | `ls -la` 有记录 |
| healthcheck | URL 可达 | HTTP 状态码可获取 |
| resourcelimit | 内存/CPU 限制可见 | `dokku resource:report` 有值 |
| alert | 规则定义可见 | `devops-alerts.json` 可读 |

### 健康指数计算

```
HealthScore = (ok_count / total_objects) × 100
≥ 80 → 🟢
≥ 60 → 🟡
< 60 → 🔴 阻塞部署
```

## Output

```json
{
  "capability": "observability",
  "pass": true,
  "health_score": 95,
  "objects_checked": 15,
  "objects_visible": 14,
  "objects_dark": 1,
  "dark_objects": ["app:adscope"],
  "timestamp": "2026-07-25T12:00:00Z"
}
```

## Interface Contract

- **调用方**: 所有 D01-D08 阶段完成后
- **输出**: `reports/devops-obs-observability.json`
- **约定**: 健康指数 <60 → 阻塞；dark_objects 非空 → 告警
