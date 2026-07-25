# DO3 — 📐 可审计 (Auditability)

> 层: L4 横切层 | 版本: v1.0
> 单一职责: 验证任何变更可被独立第三方逐项检查
> 依赖: `devops-00-architecture.md`

## Trigger: 每个阶段完成后 / `devops verify --capability auditability`

## Input: `reports/devops-registry.json` + `reports/devops-events.jsonl`

## Action

| 审计项 | 阻断机制 | 通过标准 |
|------|:---:|------|
| 代码提交 | pre-commit 阻断语法错误 | 0 syntax error |
| 密钥泄露 | 硬编码扫描阻断 | 0 hardcoded |
| 部署审批 | Staging 冒烟不通过→禁生产 | staging pass=true |
| 配置变更 | 密钥不可回读 | `dokku config:get` 需 SSH |
| 回滚操作 | EventStore 含审批记录 | who+when+why 完整 |
| 对象删除 | 只标记 gone，不真删 | gone 对象保留 ≥30 天 |

## Output

```json
{
  "capability": "auditability",
  "pass": true,
  "audit_checks": 6,
  "passed": 6,
  "failed": 0,
  "findings": []
}
```

## Interface Contract

- **调用方**: D01-D08 / 手动
- **输出**: `reports/devops-obs-auditability.json`
- **约定**: 任一项 fail → 阻塞
