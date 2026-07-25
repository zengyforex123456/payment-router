# DO5 — 🧬 可进化 (Evolvability)

> 层: L4 横切层 | 版本: v1.0
> 单一职责: 验证系统能在不改已有模块的前提下安全演化
> 依赖: `devops-00-architecture.md` · `12-pipeline-evolution.md`

## Trigger: 新模块/对象类型添加时 / `devops verify --capability evolvability`

## Input: `reports/devops-registry.json` + `module_registry.json`

## Action

| 演化机制 | 检查项 | 通过标准 |
|------|------|------|
| 新对象类型 | 只需加 discover 函数，不改 registry 核心 | discover.sh 每个类型独立函数 |
| 新阶段 | 只需加 run_xxx() 函数，不改 pipeline 主循环 | pipeline.sh case 语句只加不删 |
| 新六可 | 只需加 DOx 文件，不改已有 DOx | 文件独立 |
| 影子模式 | 新功能 ≥3 次"输出不决策" | 影子计数器 ≥3 |
| 知识积累 | 故障→修复→Runbook→指纹 | 每次故障更新指纹库 |
| 阈值自适应 | 监控阈值基于历史动态调整 | 每周自动校准 |

## Output

```json
{
  "capability": "evolvability",
  "pass": true,
  "new_since_last": ["app:adscope"],
  "modules_untouched": 22,
  "shadow_mode_candidates": [],
  "timestamp": "..."
}
```

## Interface Contract

- **输出**: `reports/devops-obs-evolvability.json`
- **约定**: 改动旧模块 → 告警；新对象未影子模式 → 建议
