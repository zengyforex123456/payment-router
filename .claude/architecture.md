# Converge 架构文档 v2.1

> 2026-07-19 | 配套 PRD: `.claude/prd.md` | P0 已完成 | 审查通过

---

## 一、当前架构 (P0 后)

```
┌── app/ (纯框架层 — 6 目录, 零业务代码) ─────────────────────┐
│                                                              │
│  Core/         Foundation/    I18n/    Tool/    UI/    Security/ │
│  容器·事件总线  日志·缓存·DB   国际化   工具网格  通用组件 认证·加密 │
│                                                              │
│  🆕 ConnectionInterface  ← 数据库抽象层 (MySQL/PgSQL)         │
└──────────────────────────────────────────────────────────────┘
        │ 框架服务                        │ Hooks 总线
        ▼                                 ▼
┌── modules/ (业务模块层 — 42 个六边形模块) ──────────────────────┐
│                                                              │
│  岛1: Affiliate/          岛3: Tracking/                     │
│  ┌──────────────────┐    ┌──────────────────────────┐       │
│  │ 推广客生命周期     │    │ 点击→转化→回传            │       │
│  │ Domain/Affiliate  │    │ Redirector              │       │
│  │  - register()     │    │ ConversionTracker       │       │
│  │  - approve()      │    │   → doAction(           │       │
│  │  - addEarnings()  │    │     'conversion.        │       │
│  │  - withdraw()     │    │      tracked', ...)     │       │
│  └────────┬──────────┘    └────────────┬─────────────┘       │
│           │                            │                     │
│           │    岛4: AffiliateCommission/ (NEW 桥接)           │
│           │    ┌──────────────────────────────────┐          │
│           ├───→│ AccrueCommissionUseCase           │←─────────┤
│           │    │  - 监听 conversion.tracked Hook    │          │
│           │    │  - 查 click.affiliate_id          │          │
│           │    │  - 解析三级费率                   │          │
│           │    │  - 计算佣金 (纯函数)               │          │
│           │    └──────────────┬───────────────────┘          │
│           │                   │                              │
│           │    岛2: SaasReferral/ (原 app/SaaS, 已迁移)       │
│           │    ┌──────────────────────────────────┐          │
│           └───→│ CommissionLedger                  │          │
│                │  - accrue()         SaaS 路径     │          │
│                │  - accrueAffiliate() 联盟路径 🆕   │          │
│                │  - approve() / markPaid()         │          │
│                │  - leaderboard() / stats()        │          │
│                │ BillingGate                       │          │
│                │  - massPayout() (Cryptomus USDT)   │          │
│                └──────────────────────────────────┘          │
│                                                              │
│  Hooks 总线:  conversion.tracked | ui.dock.panels | init     │
│  禁止: 跨模块直接 use | 直接读对方 Repository | 循环依赖       │
└──────────────────────────────────────────────────────────────┘
```

---

## 二、数据库抽象层 (ConnectionInterface)

### 问题

当前所有 Repository 构造函数接受 `\mysqli $db`，硬绑定 MySQL。新增 PostgreSQL 需要改所有 Repository。

### 方案

```
Repository → ConnectionInterface → MysqlConnection (MySQL)
                                 → PgsqlConnection (PostgreSQL)
```

### 接口定义

```php
// app/Foundation/Database/ConnectionInterface.php
namespace Converge\Foundation\Database;

interface ConnectionInterface
{
    /** 参数化查询，返回结果集 */
    public function query(string $sql, array $params = []): ResultSet;

    /** 执行写操作，返回 affected rows */
    public function execute(string $sql, array $params = []): int;

    public function lastInsertId(): int;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
    public function getDriver(): string; // 'mysql' | 'pgsql'
}
```

### MySQL 适配器 (薄封装现有 mysqli)

```php
// app/Foundation/Database/MysqlConnection.php
class MysqlConnection implements ConnectionInterface
{
    private \mysqli $db;

    public function __construct(\mysqli $db) {
        $this->db = $db;
    }

    public function query(string $sql, array $params = []): ResultSet
    {
        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            $types = ''; $values = [];
            foreach ($params as $p) {
                if (is_int($p)) { $types .= 'i'; }
                elseif (is_float($p)) { $types .= 'd'; }
                else { $types .= 's'; }
                $values[] = $p;
            }
            $stmt->bind_param($types, ...$values);
        }
        $stmt->execute();
        return new MysqlResultSet($stmt->get_result());
    }
    // ... execute, lastInsertId, transaction methods
}
```

### PostgreSQL 适配器 (PDO pgsql)

```php
// app/Foundation/Database/PgsqlConnection.php
class PgsqlConnection implements ConnectionInterface
{
    private \PDO $pdo;

    public function __construct(string $dsn, string $user, string $pass) {
        $this->pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function query(string $sql, array $params = []): ResultSet
    {
        // 自动处理 MySQL→PgSQL 方言差异
        $sql = $this->translateSql($sql);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return new PgsqlResultSet($stmt);
    }

    private function translateSql(string $sql): string
    {
        // `table` → "table"
        // AUTO_INCREMENT → SERIAL (只在 CREATE TABLE 时)
        // LIMIT x OFFSET y (不变，PgSQL 也支持)
        return $sql;
    }
    // ...
}
```

### Repository 迁移 (零 SQL 变更)

```php
// 旧: 硬绑定 MySQL
class MysqlAffiliateRepository implements AffiliateRepositoryInterface
{
    public function __construct(\mysqli $db) { ... }
}

// 新: 注入接口，支持任意数据库
class MysqlAffiliateRepository implements AffiliateRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $db
    ) {}
}
```

### 迁移步骤

| 步骤 | 内容 | 影响 |
|:---:|------|------|
| 1 | 创建 ConnectionInterface + MysqlConnection | 零影响 |
| 2 | 创建 PgsqlConnection | 零影响 |
| 3 | Repository 构造函数改为 ConnectionInterface | 改签名，不改逻辑 |
| 4 | 工厂函数根据 `DB_DRIVER` 环境变量选择实现 | 配置驱动 |
| 5 | SQL 迁移脚本适配 PostgreSQL 方言 | 按需 |

---

## 三、模块依赖声明 (P1 — 架构评审反馈)

### 问题

模块间依赖是隐式的（代码中直接 use），无法自动检测循环依赖，模块无法独立升级。

### 方案

在 `module.json` 中增加 `depends_on`：

```json
{
  "name": "AffiliateCommission",
  "version": "1.0.0",
  "depends_on": ["Affiliate", "SaasReferral", "Tracking"]
}
```

`ModuleLoader` 加载时:
1. 拓扑排序，检测循环依赖
2. 依赖未就绪 → 拒绝加载，报错
3. 加载顺序: 无依赖模块 → 被依赖模块 → 依赖者

### 最终模块依赖图

```
                    ┌──────────┐
                    │ Tracking │  (无依赖)
                    └────┬─────┘
                         │ depends_on
              ┌──────────┼──────────┐
              ▼          ▼          ▼
        ┌──────────┐ ┌──────────┐ ┌──────────────────┐
        │Affiliate │ │SaasReferral│ │AffiliateCommission│
        │(无依赖)  │ │(无依赖)   │ │(依赖上面三个)     │
        └──────────┘ └──────────┘ └──────────────────┘
```

---

## 四、架构改进路线图 (架构评审反馈)

| 优先级 | 改进项 | 工作量 | 说明 |
|:---:|------|:---:|------|
| P1 | 模块依赖显式声明 | 2h | module.json + depends_on, ModuleLoader 拓扑排序 + 循环检测 |
| P1 | 模块独立测试目录 | 2h | modules/*/tests/, `bin/platform test --module=X` |
| P2 | 模块配置自包含 | 1h | modules/*/config/, `bin/platform config:sync` |
| P2 | 跨模块数据边界 | 4h | 只读视图 + 事件/命令, 禁止跨模块 JOIN |
| P3 | 领域事件标准化 | 4h | EventBus::publish/subscribe 替代裸 Hook |
| P3 | 模块级 API 契约 | 4h | ModuleContract interface, ModuleLoader::getContract() |
| P3 | app/Security vs modules/Security 明确 | 1h | 基础设施 vs 业务规则 |
| P5 | ConnectionInterface + PgSQL | 1d | 数据库抽象层 |
| 远期 | CQRS 读写分离 | 1w | Tracking 写入/查询分离 |

---

## 五、模块契约 (ModuleContract)

### 问题

模块通过服务类互相调用，但未显式声明"模块对外提供哪些能力"。其他模块直接实例化内部类，破坏封装。

### 方案

每个模块定义一个 `Contract` 接口，通过 `ModuleLoader::getContract()` 获取：

```php
// modules/Affiliate/Contract/AffiliateContract.php
namespace Converge\Modules\Affiliate\Contract;

interface AffiliateContract
{
    public function register(array $data): Affiliate;
    public function approve(int $affiliateId): void;
    public function getEarnings(int $affiliateId): float;
    public function getByCode(string $code): ?Affiliate;
    public function isActive(int $affiliateId): bool;
}
```

```php
// modules/Affiliate/Module.php
class AffiliateModule implements ModuleInterface
{
    public function getContract(): AffiliateContract
    {
        return new AffiliateService(
            new MysqlAffiliateRepository($this->db)
        );
    }
}
```

```php
// 其他模块调用 — 只依赖接口，不依赖具体类
use Converge\Modules\Affiliate\Contract\AffiliateContract;

$affiliate = ModuleLoader::get('Affiliate')
    ->getContract()
    ->getByCode('AFF_ABCD1234');
```

### 契约设计原则

| 原则 | 说明 |
|------|------|
| 接口暴露 ≤5 方法 | 超过 → 拆分为多个契约 |
| 参数用 DTO/值对象 | `register(AffiliateData $dto)` 非 `register(string,int,string,...)` |
| 返回类型明确 | `?Affiliate` 非 `array\|null` |
| 异常语义化 | `AffiliateNotFoundException` 非通用 `\Exception` |

---

## 六、领域事件标准化 (EventBus)

### 问题

当前通过 `Hooks::doAction('conversion.tracked', $payload)` 通信。Hook 是字符串标签，无类型检查，重构时 IDE 无法追踪。

### 方案: 强类型领域事件

```php
// modules/Tracking/Events/ConversionTracked.php
namespace Converge\Modules\Tracking\Events;

class ConversionTracked
{
    public function __construct(
        public readonly int $clickId,
        public readonly int $conversionId,
        public readonly float $value,
        public readonly int $campaignId,
        public readonly ?int $offerId,
        public readonly ?int $affiliateId,
        public readonly string $occurredAt,  // ISO 8601
    ) {}
}
```

```php
// 发布 (TrackClickUseCase 中)
use Converge\Core\Event\EventBus;

$event = new ConversionTracked(
    clickId: $click->id,
    conversionId: $conversion->id,
    value: $conversion->value,
    campaignId: $click->campaignId,
    offerId: $conversion->offerId,
    affiliateId: $click->affiliateId,
    occurredAt: date('c'),
);
EventBus::publish($event);
```

```php
// 订阅 (AffiliateCommission bootstrap.php)
use Converge\Core\Event\EventBus;
use Converge\Modules\Tracking\Events\ConversionTracked;

EventBus::subscribe(ConversionTracked::class, function (ConversionTracked $event): void {
    if ($event->affiliateId === null) return; // 非推广客转化，跳过

    $useCase = new AccrueCommissionUseCase(/* ... */);
    $useCase->execute(
        affiliateId: $event->affiliateId,
        clickId: $event->clickId,
        conversionId: $event->conversionId,
        value: $event->value,
        campaignId: $event->campaignId,
        offerId: $event->offerId,
    );
});
```

### EventBus vs Hooks 边界

| 场景 | 用 EventBus | 用 Hooks |
|------|:---:|:---:|
| 跨模块领域事件 (conversion.tracked) | ✅ | — |
| UI 菜单注册 (ui.dock.panels) | — | ✅ |
| 模块初始化 (init) | — | ✅ |
| 数据流传递 (click→commission) | ✅ | — |
| 插件扩展点 (filter content) | — | ✅ |

**规则**: 数据/业务事件 → EventBus；UI/初始化 → Hooks。

---

## 七、模块独立测试结构

### 问题

测试集中在根目录 `tests/`，模块移植时测试不跟随。

### 方案

```
modules/Campaign/
├── tests/
│   ├── Unit/
│   │   ├── Domain/CampaignTest.php           ← 实体状态转换
│   │   └── Application/CreateCampaignUseCaseTest.php
│   ├── Integration/
│   │   └── Infrastructure/MysqlCampaignRepositoryTest.php
│   └── bootstrap.php                         ← 测试引导 (DB fixture)
├── Domain/
├── Application/
├── Infrastructure/
├── Controller/
└── bootstrap.php
```

### 执行

```bash
# 跑单个模块
bin/platform test --module=Campaign

# 跑全部模块
bin/platform test --all

# 只跑单元测试
bin/platform test --module=Campaign --suite=Unit

# CI 模式 (JSON 输出)
bin/platform test --module=Campaign --json
```

### 基类抽象

```php
// modules/Campaign/tests/bootstrap.php
// 复用 AbstractModuleTestCase (app/Foundation/Testing/)
abstract class AbstractModuleTestCase extends \PHPUnit\Framework\TestCase
{
    protected ConnectionInterface $db;

    protected function setUp(): void
    {
        $this->db = ConnectionFactory::create($_ENV['DB_DRIVER'] ?? 'mysql');
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db->rollback();  // 测试不污染数据库
    }
}
```

---

## 八、app/Security vs modules/Security 边界

### 当前状态

`app/Security/` 存在且提供认证/授权基础设施。**不存在** `modules/Security/`。

### 职责边界

| 层 | 目录 | 职责 | 示例 |
|------|------|------|------|
| 基础设施 | `app/Security/` | 认证(Auth)、授权(Permission)、CSRF、加密 | `Auth::requireAuth()`, `Permission::can()` |
| 业务规则 | `modules/*/Domain/` | 各模块自己的安全策略 | 推广客防欺诈规则、提现频率限制 |

### 规则

```
app/Security/  ← 回答 "这个用户是谁？能做什么操作？" (框架级)
modules/Affiliate/Domain/ ← 回答 "这个推广客行为是否正常？" (业务级)
```

```php
// ✅ 正确: 业务规则放在模块 Domain
// modules/Affiliate/Domain/AffiliateFraudPolicy.php
class AffiliateFraudPolicy
{
    public function isSuspicious(Affiliate $a, array $recentClicks): bool
    {
        return count($recentClicks) > 100
            && $this->sameIpRatio($recentClicks) > 0.8;
    }
}

// ✅ 正确: Auth 继续用 app/Security/
if (!Auth::requireAuth()) { ... }
```

### 禁止

| ❌ | ✅ |
|----|----|
| 业务规则放在 app/Security/ | 业务规则放在 modules/*/Domain/ |
| 每个模块有自己的认证逻辑 | 统一用 app/Security/Auth |
| Auth 类直接查业务表 | 通过模块 Contract 获取 |

---

## 九、禁止模式 (全局)

| ❌ | ✅ |
|----|----|
| 跨模块直接 `use OtherModule\Class` | 通过 Hooks::doAction/EventBus |
| 跨模块直接读对方 Repository | 通过数据契约 (只读视图) |
| 硬编码 `new \mysqli` | ConnectionInterface 注入 |
| 模块放 app/ 目录 | 业务模块放 modules/ |
| 裸 `json_encode` 输出到 HTML | `json_encode($data, JSON_HEX_APOS \| JSON_HEX_TAG)` → `window.__DATA` |
| 硬编码颜色 `#3b82f6` | `var(--color-primary)` |
| 内联 `<script>` >20 行 | 独立 .js 文件 |

---

## 六、部署视图

```
Docker Compose
═══════════════
     Nginx
       │
  ┌────▼────┐     ┌──────────┐     ┌──────────┐
  │ PHP 8.2 │────│  MySQL   │ 或  │PostgreSQL│  ← ConnectionInterface 切换
  │Converge │     │  8.0     │     │   16     │
  └────┬────┘     └──────────┘     └──────────┘
       │
  ┌────▼────┐     ┌──────────┐
  │  Redis  │     │Cryptomus │  (USDT 批量付款)
  └─────────┘     └──────────┘

app/      ← 框架 (不改)
modules/  ← 业务 (四模块: Affiliate, SaasReferral, Tracking, AffiliateCommission)
public/   ← 入口 (affiliate.php, commissions.php, ...)
templates/← Latte 视图
```
