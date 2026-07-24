# 架构健康度持续检测 — 适应度函数·六边形合规·模块化单体演化

> 层: L3 领域规格 | 版本: v1.0 | 适用: Converge 项目
> 数据来源: Building Evolutionary Architectures (Ford/Parsons/Kua 2025) + DDD Modular Monolith 最佳实践
> 配套: `architect-reviewer` skill · `module-designer` skill

## 核心原则

**架构不是设计出来的，是演化出来的。** 适应度函数 (Fitness Function) 是架构的自动化守护者——不靠人工审查，靠可执行断言。

## 一、五维架构适应度

### 1. 结构适应度 (Structural Fitness)
自动化检测架构铁律违规：

| 检测项 | 规则 | 工具 | 阻断级别 |
|--------|------|------|:---:|
| Domain IO 隔离 | Domain/ 层零 `new mysqli`/`PDO`/`file_get_contents` | `enforce-architecture.sh` | 🔴 hard |
| 依赖方向 | Controller→Application→Domain，不可反向 | `verify-modules.php` | 🔴 hard |
| 跨模块通信 | 仅通过 Hooks/EventDispatcher，禁止直接 `use` | `verify-modules.php` | 🔴 hard |
| 文件规模 | ≤150行/文件，≤15行/Controller方法 | `arch-gate.js` | 🔴 hard |
| 循环依赖 | 模块间零循环引用 | `find-circular.cjs` | 🟡 warn |

### 2. 契约适应度 (Contract Fitness)
每个模块的公开接口稳定性检测：

| 检测项 | 规则 | 频率 |
|--------|------|:---:|
| Repository 接口方法数 | ≤5 方法（防止上帝接口） | 每次提交 |
| UseCase 参数类型 | 只依赖 Interface，不依赖 Concrete | 每次提交 |
| Hook 注册完整性 | bootstrap.php 含 `module.json` 声明的全部 Hook | 每次提交 |

### 3. 演化适应度 (Evolution Fitness)
衡量架构的可演化性：

| 指标 | 计算公式 | 目标 |
|------|---------|:---:|
| 模块独立性 | 无跨模块依赖的模块数 / 总模块数 | ≥ 80% |
| 抽象稳定性 | Interface数 / (Interface数 + Concrete类数) | 40-60% |
| 变更影响面 | 修改一个模块波及的模块数（平均） | ≤ 2 |

### 4. 测试适应度 (Test Fitness)
测试作为架构文档的可执行验证：

| 检测项 | 规则 | 阈值 |
|--------|------|:---:|
| Domain 实体测试覆盖 | 每个实体 ≥1 个状态转换测试 | 100% |
| UseCase 集成测试 | 每个 UseCase ≥1 Happy Path + ≥1 异常路径 | 100% |
| 模块契约测试 | verify-modules.php 每模块 ≥4 断言 | 100% |

### 5. AI 集成适应度 (AI Integration Fitness)
AI 能力接入的架构合规检测：

| 检测项 | 规则 |
|--------|------|
| AI Provider 封装 | 所有 LLM 调用通过 `AiProviderInterface`，不直接在 UseCase 中 `curl` |
| 断路器保护 | 所有外部 API 调用通过 `CircuitBreaker` + `RetryHandler` |
| Token 消耗追踪 | AI 调用走 `StructuredLogger`，记录 token 消耗 |
| 降级策略 | AI 不可用时业务不中断，有 fallback 响应 |

## 二、适应度函数实现模式

### 模式 1: 静态分析断言 (CI 中运行)

```php
// 检测 Domain 层 IO 污染
$domainFiles = glob('modules/*/Domain/*.php');
foreach ($domainFiles as $file) {
    $content = file_get_contents($file);
    if (preg_match('/new\s+(mysqli|PDO)\b/', $content)) {
        echo "❌ $file: Domain 禁止直接 DB 连接\n";
        exit(1);
    }
}
```

### 模式 2: 运行时遥测 (生产环境采样)

```php
// 检测跨模块直接调用
spl_autoload_register(function ($class) {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    // 判断调用方和被调方是否在不同模块
    // 若不同且非 Hooks → 告警
});
```

### 模式 3: 周期性审计 (定时任务)

```bash
# 每周生成架构健康度报告
node scripts/architecture-health-report.cjs → reports/architecture-health.json
```

## 三、模块化单体演化路线

```
阶段1: 混沌单体          阶段2: 模块化骨架       阶段3: 六边形模块化单体
──────┼──────────────────┼───────────────────────┼─────────────→
    ❌ 跨模块直接调用      ✅ 模块目录分离           ✅ 模块端口/适配器
    ❌ Domain 含 SQL       ✅ Domain 零 IO           ✅ 模块间事件通信
    ❌ 无接口约束           ✅ Repository 接口        ✅ 适应度函数自动化
    ❌ 文件 >500 行         ✅ 文件 ≤150 行           ✅ 架构健康度仪表盘
```

Converge 当前: 阶段2 → 阶段3 过渡中（28模块已迁移到六边形，Dock 侧边栏已 Hook 化）
下一步: 模块间直接 `use` 替换为 EventDispatcher 事件通信

## 四、新模块接入检查清单

任何新模块提交前，必须通过架构适应度检查：

```
□ 结构:   Domain 零 IO · 四层目录完整 · 文件 ≤150 行
□ 契约:   Repository 接口 ≤5 方法 · 依赖只指向 Interface
□ 演化:   不新增跨模块直接 use · 通过 Hooks 注册
□ 测试:   实体有状态转换测试 · UseCase 有快乐+异常路径
□ AI:     LLM 调用走 AiProviderInterface · 有断路器
```

## 五、架构健康度评分公式

```
HealthScore = 
  StructuralScore × 0.35   (结构合规)
+ ContractScore  × 0.25   (契约稳定)
+ EvolutionScore × 0.20   (演化能力)
+ TestScore      × 0.15   (测试保护)
+ AIScore        × 0.05   (AI 集成合规)
```

评级: A ≥ 85 · B ≥ 70 · C ≥ 55 · D < 55

## Interface Contract

- **消费者**: `architect-reviewer` skill（审查时读取此规则）· CI/CD 管道（每次提交运行适应度函数）
- **依赖**: `00-architecture-meta.md`（全局架构原则）· `02-ui-architecture.md`（前端架构）
- **输出格式**: 适应度报告 (JSON) + 健康度评分
- **约定**: 阻断级违规 → 阻塞提交；警告级 → P2 前修复；适应度函数自身也要演化
