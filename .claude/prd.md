# 联盟营销追踪器 需求书 v2.0

> 目标: 连接推广客 + 追踪 + 佣金三岛 | P0 迁移已完成 | 等审查后推进 P1-P4

## 0. 用户画像

| 角色 | 核心场景 | 痛点 | 触点 |
|------|---------|------|------|
| 推广客 | 注册→拿链接→推广→看收入→提现 | 不知道赚了多少、哪个 offer 好转化 | 推广仪表盘 + 推广链接 |
| 广告主 | 配置佣金率→看推广客表现→审批付款 | 不知道推广客质量、怕被刷量 | 佣金配置 + 推广客管理 |
| 平台运营 | 审核推广客→处理争议→批量付款 | 手动对账耗时 | 推广客审批 + 佣金结算 |

## 0.5 核心使用场景

### 场景A: 推广客快乐路径
1. 推广客访问 `/affiliate.php` → 注册 (R1, POST /api/affiliates/register)
2. 管理员审批 → active (R2, POST /api/affiliates/approve)
3. 推广客复制推广链接 `?aff=AFF_XXX` (R3, GenerateAffiliateLinkUseCase)
4. 用户点击 → Redirector 记录 `affiliate_id` 到 clicks (R4)
5. 用户转化 → ConversionTracker 触发 Hook → 佣金应计 (R5)
6. 推广客看仪表盘: 点击/转化/收入/ROI (R6)
7. 达到最低提现额 → 提现 (R7)

### 场景B: 刷量检测
1. 同一 IP 短时间大量点击 → BotDetector 标记 suspicious (R8)
2. suspicious 转化不计佣金
3. 推广客可申诉 → 管理员手动放行或封禁

## 1. 问题陈述

**当前状态 (P0 后)**: app/ 已清理，SaasReferral 归位到 modules/。但三岛仍互不通信:
- `modules/Affiliate/` 控制器孤立，未被调用
- `modules/Tracking/` 无 affiliate_id 概念
- `modules/SaasReferral/` 只有 SaaS 返佣路径，无联盟佣金路径

**核心问题**: 推广客注册后无法生成追踪链接、点击不归因、转化不计佣。

**影响**: 无法运营联盟营销 — 追踪平台最核心的变现模式。

## 2. 方案对比

| 维度 | 方案C: 迁移+桥接层 ✅ |
|------|------|
| 做法 | P0 已完成(app/SaaS→modules/SaasReferral)，新建 AffiliateCommission 桥接模块 |
| 佣金引擎 | 复用 CommissionLedger 状态机+付款+排行榜，新增 `accrueAffiliate()` 路径 |
| app/ 目录 | 只剩 6 个纯框架目录 |
| 改动面 | AffiliateCommission(新) + clicks 表 +affiliate_id + CommissionLedger 加方法 |
| 风险 | 低 — 新增不改旧，Hooks 解耦 |

## 3. 数据层接口 (DatabaseInterface)

> 问题: 当前所有 Repository 直接 `new \mysqli`，硬绑定 MySQL。新增 PostgreSQL 需抽象。

### 方案: Repository 注入 ConnectionInterface

```
                         ┌─ MysqlConnection
Repository → ConnectionInterface ─┤
                         └─ PgsqlConnection
```

```php
// app/Foundation/Database/ConnectionInterface.php (NEW)
interface ConnectionInterface {
    public function query(string $sql, array $params = []): ResultSet;
    public function execute(string $sql, array $params = []): int; // affected rows
    public function lastInsertId(): int;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
}

// 现有 Repository 变更 (以 Affiliate 为例):
// 旧: public function __construct(\mysqli $db)
// 新: public function __construct(ConnectionInterface $db)
```

### MySQL 适配器 (薄封装)

```php
// app/Foundation/Database/MysqlConnection.php (NEW)
class MysqlConnection implements ConnectionInterface {
    private \mysqli $mysqli;
    // 把现有 mysqli 调用包装为实现 ConnectionInterface
    // 零业务逻辑，纯 SQL 执行
}
```

### PostgreSQL 适配器 (新增)

```php
// app/Foundation/Database/PgsqlConnection.php (NEW)
class PgsqlConnection implements ConnectionInterface {
    private \PDO $pdo;
    // PDO pgsql 实现，处理 MySQL→PostgreSQL 的 SQL 方言差异
    // 关键差异: LIMIT/OFFSET, AUTO_INCREMENT→SERIAL, `→"
}
```

### 迁移策略

| 阶段 | 动作 | 影响 |
|:---:|------|------|
| 1 | 创建 ConnectionInterface + MysqlConnection | 零影响 — 薄封装 |
| 2 | 逐个 Repository 改为接受 ConnectionInterface | 改构造函数签名，不改 SQL |
| 3 | 创建 PgsqlConnection | 新增 |
| 4 | 配置驱动切换: `DB_DRIVER=mysql\|pgsql` | 环境变量 |

**原则**: MySQL 用户不受影响；PostgreSQL 用户只需改环境变量。

## 4. 架构设计

### 模块协作 (Hooks 驱动，不改旧代码)

```
Tracking::conversion.tracked Hook
  │
  └──→ AffiliateCommission::AccrueCommissionUseCase
        │
        ├── 查找 click.affiliate_id (← Redirector ?aff=)
        ├── 解析三级费率: Offer > Campaign > Affiliate
        ├── 计算佣金: value × rate (纯函数, 零 IO)
        ├── 调 CommissionLedger::accrueAffiliate() (复用状态机+付款)
        └── 调 Affiliate::addEarnings() (更新推广客余额)
```

### 数据模型 (新增)

```sql
ALTER TABLE clicks ADD COLUMN affiliate_id INT NULL;
ALTER TABLE clicks ADD INDEX idx_affiliate (affiliate_id);

CREATE TABLE IF NOT EXISTS affiliate_marketing_commissions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  affiliate_id INT NOT NULL, click_id BIGINT NOT NULL,
  conversion_id BIGINT NOT NULL, campaign_id INT, offer_id INT,
  conversion_value DECIMAL(10,2), commission_value DECIMAL(10,2),
  rate DECIMAL(5,4), status ENUM('pending','approved','paid','reversed') DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (affiliate_id) REFERENCES affiliates(id)
) ENGINE=InnoDB;

ALTER TABLE affiliates ADD COLUMN commission_rate DECIMAL(5,4) DEFAULT 0.2000;
```

### 关键设计决策

1. **P0 迁移已完成** — app/SaaS/ 删除，SaasReferral 归位 modules/
2. **新增不改旧** — AffiliateCommission 新模块，Tracking 零修改
3. **CommissionLedger 双路径** — accrue()(SaaS) + accrueAffiliate()(联盟)
4. **三级费率** — Offer > Campaign > Affiliate
5. **数据库抽象** — ConnectionInterface 支持 MySQL/PostgreSQL 切换
6. **Hooks 唯一通信通道** — 跨模块不直接 use

## 5. 演进路线图

| Phase | 交付物 | 状态 | 验收日期 |
|------|------|:---:|------|
| **P0** | app/SaaS → modules/SaasReferral | ✅ 完成 | 2026-07-14 |
| **P1** | 推广链接生成 + clicks 归因 | ✅ 完成 | 2026-07-15 |
| **P2** | 转化→佣金应计 (CommissionLedger::accrueAffiliate) | ✅ 完成 | 2026-07-16 |
| **P3** | 推广客仪表盘 (点击/转化/收入/ROI) | ✅ 完成 | 2026-07-18 |
| **P4** | 批量付款 + 防刷量 | ✅ 完成 | 2026-07-19 |
| **P5** | ConnectionInterface + PostgreSQL 适配器 | 🟡 MySQL完成, PgSQL未实现 | — |

## 6. 需求清单

| ID | 功能 | 描述 | 优先级 | 验收 | 实现文件 |
|------|------|------|:---:|------|------|
| R1 | 推广客注册 | 复用 RegisterAffiliateUseCase | P0 | POST 返回 referral_code | `modules/Affiliate/Application/RegisterAffiliateUseCase.php` |
| R2 | 推广客审批 | 复用 ApproveAffiliateUseCase | P0 | pending→active | `modules/Affiliate/Application/ApproveAffiliateUseCase.php` |
| R3 | 推广链接生成 | campaign_id+affiliate_code→URL | P0 | URL 含 `?aff=AFF_XXX` | `modules/AffiliateCommission/Application/GenerateAffiliateLinkUseCase.php` |
| R4 | 点击归因 | Redirector 解析 aff→clicks | P0 | clicks.affiliate_id 非空 | `modules/Tracking/Infrastructure/Redirect/ClickStore.php` |
| R5 | 转化归因 | Hook→AccrueCommissionUseCase | P0 | 佣金记录创建 | `modules/AffiliateCommission/Application/AccrueCommissionUseCase.php` |
| R6 | 推广客仪表盘 | 点击/转化/收入/ROI API | P1 | 数据正确 | `public/affiliate-marketing.php` + `DashboardController.php` |
| R7 | 佣金提取 | 复用 WithdrawCommissionUseCase | P1 | 余额减少 | `modules/Affiliate/Application/WithdrawCommissionUseCase.php` |
| R8 | 刷量检测 | BotDetector 5层引擎 | P2 | suspicious 不计佣 | `app/Security/BotDetector.php` |
| R9 | 数据库抽象 | DatabaseInterface + MysqlAdapter | P5 | MySQL/PgSQL 切换 | `app/Contracts/DatabaseInterface.php` (PgSQL待实现) |

## 7. 审计修正

| 版本 | 修正项 | 原因 |
|------|------|------|
| v1.0 | 初始方案C | 独立佣金引擎 |
| v1.1 | 增加 P0: app/SaaS 迁移 | 架构债务清理前置 |
| v2.0 | P0 完成; +ConnectionInterface; +PgSQL | 架构评审反馈 |
| v2.1 | P1-P4 全部完成; R1-R8 验收通过 | 2026-07-20 需求vs实际审计 |
| v2.2 | 数据库抽象重命名为DatabaseInterface(非ConnectionInterface) | 实际实现使用DatabaseInterface+MysqlAdapter |
| v2.3 | BotDetector 远超 PRD: 5层引擎(原设计仅IP+频率) | 实现比需求更完善 |

## 8. 增强缺口

| 缺口 | 优先级 | 计划 |
|------|:---:|------|
| 多级返佣(二级) | P2 | Phase 4 |
| PostgreSQL 适配器 | P2 | P5 |
| Cookie 归因(30天) | P2 | Phase 3 |
| 推广客专属 Landing Page | P3 | 未来 |
| 推广客排行榜 | P3 | 未来 |
| CQRS 读写分离 | 远期 | 1周 |
