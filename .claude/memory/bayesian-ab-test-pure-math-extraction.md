---
name: bayesian-ab-test-pure-math-extraction
description: 从DB依赖的ABTestEngine提取纯统计函数 StatisticalSignificance 供Domain层使用
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# Bayesian A/B Testing — 纯数学提取 + 模块接入

**检测模式**: `modules/ABTest/Domain/ABTest::isSignificant()` 使用启发式 (100样本/10%差值)
**根因**: `ABTestEngine` 有完整 Bayesian Monte Carlo 但方法是 private 且类依赖 DB；模块无法复用
**影响**: A/B测试结果不可靠 — 100样本的启发式远不如 Bayesian 10K Monte Carlo 模拟

**修复**:
1. 创建 `src/Evolution/StatisticalSignificance.php` — 纯静态方法：`probabilityABetter()`, `evaluate()`, `sampleBeta()`, `sampleGamma()`, `boxMuller()`。零IO，零DB依赖
2. `ABTestEngine` 私有数学方法委托给 `StatisticalSignificance`（~130行删除）
3. `ABTest::isSignificant()` / `winner()` 改用 `StatisticalSignificance::evaluate()` — Beta-Binomial + 10K模拟 + 95%置信度 + 200最小样本
4. 修复 `MysqlABTestRepository` 查询不存在表的bug → `ab_test_conversions` 表含 clicks+conversions
5. Migration 078: 加 `variants` 列 + `ab_test_conversions` 表

**验证**: 34 tests, 271 assertions
**关键**: Marsaglia-Tsang Gamma采样中 `$u` 变量需在 do-while 前初始化（`$v <= 0` 时 continue 跳过赋值）
