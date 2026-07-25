# DO2 — 📋 可追溯 (Traceability)

> 层: L4 横切层 | 版本: v1.0
> 单一职责: 验证任何事件可沿时间线回溯到源头
> 依赖: `devops-00-architecture.md` · `devops-0r-registry.md`

## Trigger

每个阶段完成后自动触发 / `devops verify --capability traceability`

## Input

- `reports/devops-registry.json` (对象 + diff)
- `reports/devops-events.jsonl` (EventStore)
- git log (代码变更追溯)

## Action

### 验证规则

| 事件类型 | 追溯要求 | 缺失时 |
|------|------|------|
| 代码变更 | git commit (who+when+what) | 告警 |
| 构建 | Docker image SHA + build time | 告警 |
| 部署 | EventStore: app+commit+timestamp | 阻断 |
| 配置变更 | EventStore: old→new + reason | 告警 |
| 故障 | EventStore: timeline+impact+fix | 阻断 |
| 回滚 | EventStore: who+when+why | 阻断 |
| 备份 | 文件名含时间戳 `backup-{db}-{YYYYMMDD}` | 告警 |

### 追溯链完整性检查

```
每个 Deployment 必须有:
  commit SHA → git log 中可找到 → Docker image 层可追溯 → 部署时间可查 → 回滚可执行
```

## Output

```json
{
  "capability": "traceability",
  "pass": true,
  "events_checked": 42,
  "events_with_trace": 40,
  "events_missing_trace": 2,
  "missing_trace": [
    {"type": "deployment", "app": "adscope", "missing": "timestamp"}
  ],
  "timestamp": "2026-07-25T12:00:00Z"
}
```

## Interface Contract

- **调用方**: 所有 D01-D08 阶段完成后
- **输出**: `reports/devops-obs-traceability.json`
- **约定**: 部署/故障/回滚 缺追溯 → fail；备份缺失 → warn
