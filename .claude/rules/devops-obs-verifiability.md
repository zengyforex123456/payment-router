# DO4 — ✅ 可验证 (Verifiability)

> 层: L4 横切层 | 版本: v1.0
> 单一职责: 验证任何断言可通过自动化实验证伪
> 依赖: `devops-00-architecture.md`

## Trigger: 每个阶段完成后 / `devops verify --capability verifiability`

## Input: `reports/devops-registry.json` + 各阶段 result JSON

## Action

| 验证项 | 方法 | 失败动作 |
|------|------|------|
| 代码语法 | `php -l` / `node --check` | 阻断 commit |
| 单元测试 | phpunit/pytest | 阻断构建 |
| 安全漏洞 | `trivy image` | 高危阻断 |
| 部署健康 | `curl /health` → 200 | 自动 rollback |
| 备份恢复 | 每月还原测试 | 告警 |
| Staging 冒烟 | 关键 API 测试 | 阻断生产 |

## Output

```json
{
  "capability": "verifiability",
  "pass": true,
  "assertions": 6,
  "verified": 6,
  "falsified": 0,
  "timestamp": "..."
}
```

## Interface Contract

- **输出**: `reports/devops-obs-verifiability.json`
- **约定**: 任一项 falsified → fail
