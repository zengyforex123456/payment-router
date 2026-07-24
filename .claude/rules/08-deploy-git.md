# Git 工作流 + 部署发布

> 层: L2 工程规范 | 版本: v1.0 | 替换: 04-deploy.md

## Trigger

| 触发词 | 动作 |
|--------|------|
| "发布" "部署" "上线" "发版" | 完整发布流水线 |
| "发布检查" "pre-release" | 仅检查清单 |
| "回滚" "rollback" | 紧急回滚 |
| "CI/CD" "流水线" | DevOps 管道配置 |
| "监控" "告警" | SRE 监控配置 |

## Input

- 代码（通过 P4 审查）
- 审查报告（来自 `06-review-process.md`）
- 测试报告（来自 `05-test-standards.md`）

## Action

### Git 分支命名

```
feature/<描述>  fix/<描述>  refactor/<描述>  docs/<描述>  test/<描述>
```

从最新 main/master 创建，小写+连字符。

### 安全规则

| 类别 | 操作 | 说明 |
|------|------|------|
| 禁止 | `push --force`(main), `reset --hard`, `clean -f`, `branch -D` | 除非用户明确要求 |
| 需确认 | `push`(首次), `merge`(到 main), `rebase` | 向用户确认 |
| 安全 | `status`, `diff`, `log`, `branch`, `stash` | 可自由执行 |

冲突解决：优先 `git merge`，逐文件解决保留双方改动，复杂冲突确认后再提交。

### 发布流水线

```
Step 1: 发布前检查
  TSC + Build + Unit + E2E + Audit + Git clean

Step 2: 质量门禁
  [QA] P0清零 [Security] 无高危 [SRE] 监控就绪

Step 3: Go/No-Go
  全部通过 → 构建制品 → 部署

Step 4: 黄金30分钟监控
  Error rate / API latency / Crash rate
```

### 发布检查清单

```
□ TSC 0 errors
□ Build success
□ Unit tests all pass
□ E2E >= 90%
□ npm audit 0 high/critical
□ Git clean
```

## Output

- 部署的制品
- 发布说明
- 监控报告（30 分钟后）

## Interface Contract

- **消费者**: `01-sdlc-lifecycle.md`（P5 部署阶段）
- **依赖**: `06-review-process.md`（审查报告）、`05-test-standards.md`（测试结果）、`13-distributed-verification.md`（部署后验证）
- **输出格式**: 制品 + 发布说明
- **约定**: P4 审查通过后方可部署；30 分钟监控窗口；禁止 force push main
