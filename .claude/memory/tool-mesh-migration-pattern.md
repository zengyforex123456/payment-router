---
name: tool-mesh-migration-pattern
description: "零散脚本→#[Tool]统一命令的迁移模式 — 经 enforcement 和 deploy 两次验证"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 88edd302-119f-44ea-bb1f-3266751e0c7d
  modified: 2026-07-19T06:07:43.664Z
---

# #[Tool] 网格迁移模式（已验证 2 次）

**适用场景**: 项目中出现 5+ 个功能脚本分散在多个目录，需要统一管理

**检测模式**: `scripts/` 和 `data/scripts/` 同时存在功能相似的脚本

## 迁移步骤（6 步）

```
Step 1: 侦察 → 列出所有脚本，按功能分组（部署/验证/回滚/状态）
Step 2: 设计接口 → 定义 #[Tool] 参数（env/action/method/tag）
Step 3: 创建工具类 → tools/XxxTool.php implements ToolInterface
Step 4: 创建命令入口 → bin/platform-xxx.php → cmdXxx()
Step 5: 更新路由 → bin/platform 添加 match 分支
Step 6: 标记废弃 → 旧目录加 DEPRECATED 文件 → 后续删除旧脚本
```

## 第一次验证: Enforcement（6 脚本 → 13 门禁 1 命令）

```
迁移前: enforce-architecture.sh, enforce-security.php, enforce-design-tokens.php,
        enforce-scripts.sh, pre-commit (120行), 各跑各的
迁移后: bin/platform enforce (13-gate unified, pre-commit 12行)
新增: 5 个 #[Tool] 类 (EnforceArchitecture, EnforceSecurity, EnforceDesignTokens,
       EnforceScripts, CheckFire) + EnforceAggregator
```

## 第二次验证: Deployment（22 脚本 → 1 命令，本次）

```
迁移前: 22 脚本 × 2 目录 (scripts/ + data/scripts/)
        4 compose 文件 × 手动管理
迁移后: bin/platform deploy [env] [action] [--method=]
新增: DeployTool (#[Tool]) + DockerDeployer + DeployVerification
```

## 接口设计模式

```php
#[Tool(
    name: 'command-name',
    description: '一句话描述',
    category: 'deploy|enforce|migrate|audit',
    parameters: [
        'env'    => 'string:dev|staging|prod',  // 枚举参数
        'action' => 'string:action1|action2',    // 动作参数
        'dry-run' => 'bool',                      // 干跑标志
    ],
)]
class XxxTool implements ToolInterface { }
```

## 门禁：防止再次泛滥

- `enforce-directory.php` → 新 `.sh` 若含 `docker compose|deploy|rsync` → 阻断
- `DEPRECATED` 文件 → 旧目录标记，指向 `bin/platform deploy`
- `bin/tool list` → 部署类工具可发现，消除"不知道已有"的根因

**验证**: 两次迁移后，enforcement 和 deployment 都从 N→1，零回归
