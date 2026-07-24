# PaymentRouter — 用户模拟报告 & 改进清单

> 2026-07-24 | 模拟场景：新卖家从注册到 10 单日常运营

---

## 一、模拟结果总览

| 步骤 | 结果 | 说明 |
|------|:---:|------|
| 着陆页 | ✅ | 200 OK |
| 注册 | ⚠️ | API 正常，前端需改进 |
| 试用开通 | ✅ | 自动激活 14 天 |
| 创建 A 站 | ⚠️ | 返回 `id:0`，应为自增 ID |
| 创建 B 站 | ⚠️ | 同上 |
| 策略切换 | ✅ | safe_mode 应用成功 |
| 订单分发 (10单) | ✅ | HMAC + JWT + 路由正常 |
| Webhook 回调 | ✅ | 7 paid + 3 failed |
| 仪表盘 | ⚠️ | 数据正确，tenant 参数需手动传 |
| 用量追踪 | ❌ | SQL 语法错误 |

---

## 二、发现的问题

### 🔴 高优先级 (影响使用)

| # | 问题 | 位置 | 影响 |
|:---:|------|------|------|
| 1 | **Repository save() 不返回 ID** | MysqlASiteRepository, MysqlBSiteRepository | 创建后返回 `id:0`，前端无法知道真实的 ID |
| 2 | **Usage API SQL 错误** | `payment_router_usage` 表 | `year_month` 列定义有语法问题 |
| 3 | **B-Site 创建无社区版限制校验** | API 路由 | 社区版应限 1B，但 API 未拦截（FeatureGate 在路由中漏了） |
| 4 | **订单分发的 `daily_order_count` 未重置** | 缺 Cron 任务 | B 站 `daily_order_count` 只会增长，永不清零 |
| 5 | **缺少分页** | Mappings API | 订单映射列表无分页，数据量大时性能问题 |

### 🟡 中优先级 (影响体验)

| # | 问题 | 位置 | 影响 |
|:---:|------|------|------|
| 6 | **前端无错误提示** | 所有 HTML 页面 | API 返回错误时，前端只显示 `[object Object]` |
| 7 | **登录后无 Session 持久化** | app.html | 刷新页面后需重新登录 |
| 8 | **注册后无欢迎/引导** | 流程 | 注册成功直接跳转仪表盘，无新手引导 |
| 9 | **无密码强度提示** | register.html | 仅要求 >=8 位，无复杂度检查 |
| 10 | **着陆页无实际截图** | index.html | 纯文字描述，缺产品截图增加信任感 |
| 11 | **A/B 站创建后无"下一步"提示** | app.html | 创建完站点后不知道接下来做什么 |
| 12 | **管理面板和客户门户功能重复** | admin.html vs app.html | 两套 UI 功能重叠，应合并 |

### 🟢 低优先级 (后续迭代)

| # | 问题 | 位置 | 影响 |
|:---:|------|------|------|
| 13 | **缺少 Email 验证** | AuthUseCase | 注册后邮箱未验证，可随意注册 |
| 14 | **缺少密码重置** | AuthUseCase | 忘记密码无法自助恢复 |
| 15 | **无操作审计日志** | 全局 | 无法追溯谁做了什么操作 |
| 16 | **Docker 镜像需从源码编译 mysqli** | Dockerfile | 每次启动需等待 ~30s 编译扩展 |
| 17 | **JWT 在 URL 中传递** | PaymentGatewayAdapter | 浏览器历史/服务器日志会记录 token |
| 18 | **无 rate limiting** | 路由器 | API 无频率限制，可被暴力攻击 |

---

## 三、必须修复的 Bug

### Bug 1: Repository save() 返回 id=0

```php
// 当前: MysqlASiteRepository::save()
$stmt->bind_param(...);
$stmt->execute();
// ← 缺少: $id = $this->db->lastInsertId();

// 修复: INSERT 后获取 lastInsertId，更新实体的 id
```

### Bug 2: Usage API SQL 语法错误

```sql
-- 085 迁移中的 payment_router_usage 表有语法问题
-- 需要手动修复或跳过该表
```

### Bug 3: B-Site 社区版限制未生效

FeatureGate 在路由中已注册但 B-Site POST 缺少 `canAddBSite()` 校验。

### Bug 4: daily_order_count 无重置机制

需要 Cron 每日 00:00 调用 `$bSiteRepo->resetDailyCounts($tenantId)`。

---

## 四、改进建议优先级

```
本周修复 (P0):
  □ Bug 1: Repository 返回自增 ID
  □ Bug 2: Usage SQL 语法
  □ Bug 3: B-Site 社区版限制
  □ Bug 4: 每日重置 Cron

本月迭代 (P1):
  □ 密码强度校验 + Email 验证
  □ 登录 Session 持久化 (JWT HttpOnly Cookie)
  □ 新手引导流程 (注册后引导创建第一个 A/B 站)
  □ 错误提示国际化 (前端 showError() 统一处理)
  □ Dockerfile 优化 (预装 mysqli 的基础镜像)

下月迭代 (P2):
  □ 密码重置流程
  □ Rate Limiting 中间件
  □ JWT Bearer Token 替代 URL 参数
  □ 管理面板 + 客户门户合并
  □ 操作审计日志
```

---

## 五、当前可用性评估

| 维度 | 评分 | 说明 |
|------|:---:|------|
| **核心业务逻辑** | 9/10 | 路由引擎、冷却、Webhook 全部正常 |
| **API 完整性** | 8/10 | 28 端点可用，部分返回格式需统一 |
| **前端体验** | 5/10 | 页面可渲染，但交互粗糙、无错误处理 |
| **运维就绪** | 7/10 | Docker 可跑，但需优化镜像构建 |
| **商业化就绪** | 6/10 | License/Trial/Billing 齐备，但缺支付对接 |
| **总体** | **7.0/10** | 核心可用，需 P0 修复 + P1 体验打磨 |

> **结论**：PaymentRouter 核心引擎已生产就绪。修复 4 个 P0 Bug 后可上线 MVP。前端体验需要 1-2 周打磨。
