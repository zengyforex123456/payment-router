# DO6 — 🩹 可自愈 (Self-healing)

> 层: L4 横切层 | 版本: v1.0
> 单一职责: 验证系统能自动检测故障并恢复
> 依赖: `devops-00-architecture.md` · `22-error-fingerprint.md`

## Trigger: 故障发生后 / 健康检查失败后 / `devops verify --capability self-healing`

## Input: `reports/devops-registry.json` + 健康检查日志 + 错误指纹库

## Action

### 自愈矩阵

| 故障类型 | 检测方式 | 自愈动作 | 验证方式 |
|------|------|------|------|
| 进程崩溃 | Dokku checks 失败 | 自动重启容器 | `ps:report` Deployed=true |
| 内存溢出 | `docker stats` 超限 | OOM Kill + 重启 | 内存回到限制内 |
| 502 Bad Gateway | `/health` 非 200 | `ps:restart` → 仍失败 → rollback | curl 200 |
| 磁盘满 | cron 检测 >80% | 自动清理 >7 天备份 + docker prune | 磁盘 <80% |
| 部署失败 | 新版本健康检查失败 | 自动 `ps:rollback` | 旧版本恢复服务 |
| 已知错误 | 指纹匹配 | 自动应用已验证修复 | 错误消失 |

### 自愈成功率计算

```
SelfHealRate = 自动恢复次数 / (自动恢复 + 人工介入) × 100
目标: ≥ 80%
```

## Output

```json
{
  "capability": "self-healing",
  "pass": true,
  "incidents_total": 5,
  "auto_recovered": 4,
  "manual_fixed": 1,
  "self_heal_rate": 80.0,
  "active_alerts": [],
  "fingerprint_matches": 2,
  "timestamp": "..."
}
```

## Interface Contract

- **输出**: `reports/devops-obs-self-healing.json`
- **约定**: 自愈率 <60% → 告警；<40% → 阻塞（需回看哪些故障应加入自愈）
