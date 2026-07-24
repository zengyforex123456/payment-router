---
name: contract-assertion-testing
description: 运行即测试 — 边重构边验证的契约断言模式 (verify-modules.php)
metadata: 
  node_type: memory
  type: feedback
  originSessionId: c898435f-e7cd-482c-9fbb-6adbb847449c
---

# 边重构边测试 — 契约断言模式

**检测模式**: 重构中每迁移一个模块，需要立即验证 4 项契约，不等 CI

**根因**: CI 反馈太慢（push→build→fail→fix→push 循环），需要本地秒级验证

**修复**: verify-modules.php — 每模块 4 项断言，< 1 秒跑完

## 四契约模式

```php
// 每个迁移后的模块必须通过:
① 类存在      — class_exists() 验证 autoload 正确
② 纯内存实例化 — new Entity() 不连 DB (Domain 零 IO 的核心证明)
③ Domain 纯净  — grep 确认 0 处 'use Illuminate'/'new mysqli'/'new PDO'
④ 有业务方法   — 非贫血模型 (至少 1 个 public 业务方法)
```

## 使用方式

```bash
# 每迁移一个模块后立即运行:
php scripts/dev/verify-modules.php Campaign    # 单模块
php scripts/dev/verify-modules.php             # 全量 28 modules

# 输出:
#   ✓ 类存在
#   ⚠ 构造需参数 — 但仍可在测试中 new Xxx(...) 不连 DB  
#   ✓ Domain 纯净: 零 IO/框架引用
#   ✓ 有业务方法: canActivate, transitionTo, isOverBudget
```

## 与传统测试的区别

| | PHPUnit | 契约断言 |
|------|------|------|
| 编写成本 | 新建测试文件 + mock | 0 行 — 反射自动检查 |
| 运行速度 | 秒级 | 毫秒级 |
| 覆盖范围 | 测试写的逻辑 | 所有模块的结构完整性 |
| 重构安全 | 测试通过 = 逻辑正确 | 结构合规 + 零 IO 保证 |

## 集成到 CI

```bash
# pre-deploy-check.sh
php scripts/dev/verify-modules.php || exit 1  # 阻断部署
```

## 验证结果

Converge 重构中: 28 modules, 63/63 pass, 17 warnings (构造需参数 — 正常, 实体都有参数)
