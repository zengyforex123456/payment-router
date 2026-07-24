---
name: converge-refactoring-methodology
description: 从项目到平台 — 三层骨架抽离完整方法论 (Phase 1→4 + A→D)
metadata: 
  node_type: memory
  type: feedback
  originSessionId: c898435f-e7cd-482c-9fbb-6adbb847449c
---

# Converge 重构完整方法论

**Why**: 将一个业务项目重构为可复用的"业务中台骨架"，新项目 5 分钟启动获得全部工业化能力。

**How to apply**: 按 Phase 1→4 骨架提取 + Phase A→D 业务模块迁移的顺序执行。

## 四阶段骨架提取

```
Phase 1: 基建提取 → converge-core (Contracts + Core + Foundation + Security)
Phase 2: UI 提取 → converge-ui (设计令牌 + 布局组件 + 安全扫描)
Phase 3: 六边形架构 → Campaign/Click 模块试点 (Domain/Application/Infrastructure/Controller)
Phase 4: 骨架定型 → converge-skeleton (新项目模板 + reset-for-new-project.sh)
```

## 四阶段业务迁移

```
Phase A: 轻量模块 (17个, ~35 files) — 一枪头
Phase B: 基础设施 (4个, ~18 files) — 对齐 Foundation
Phase C: 核心业务 (4个, ~55 files) — 高价值实体
Phase D: 重型模块 (4个, ~90 files) — 最后攻坚
```

## 关键决策

1. Auth → Security 命名空间 (预留 SSO/OAuth/防火墙)
2. Resilience + Observability → Foundation/ 子目录 (不同运维维度)
3. Core 上帝目录 → Core/Hook + Core/Module + Core/Helper
4. Core 孤儿文件 → Foundation/System/ (DeployMode, FeatureRegistry, Snapshots)
5. 每类迁移: L1 静态扫描 → L2 断言基线 → L3 PHPUnit → L4 影子模式 → 切换

## 验证机制

- verify-refactoring.php: 39 条断言, 覆盖 autoload + Hooks + Alpine + Resilience + I18n
- verify-modules.php: 4 项契约 (类存在 + 纯内存 + 零 IO + 有业务方法)
- enforce-architecture.sh: ⑨ 条: 五原则 + Domain 纯净 + 跨模块 + Controller 行数
- pre-deploy-check.sh: 5 项部署前检查

## 迁移脚本模式

批量命名空间替换用 PHP 脚本，不用 sed (Git Bash 转义问题):
```php
$map = ['use Converge\\Auth\\Auth' => 'use Converge\\Security\\Auth', ...];
foreach ($files as $file) { str_replace(array_keys($map), array_values($map), $content); }
```

## 重构数字

- 77 个文件迁移 (src + views + public + tests)
- 4 个旧目录删除 (Auth, Core, Resilience, Observability)
- 28 个六边形模块新建
- 7 个 Contracts 接口 (Database, Auth, Event, Cache, Hook, Module + PSR-3)
- 3 个独立 Git 仓库 (converge-core, converge-ui, converge-skeleton)
- 1559 个自动加载类, 0 PSR-4 警告
- 78/78 PHPUnit 核心测试通过
