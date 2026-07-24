---
name: hexagonal-architecture-php-patterns
description: PHP 六边形架构落地模式 — Contracts→Domain→Infrastructure 四层 + 端口/适配器
metadata: 
  node_type: memory
  type: feedback
  originSessionId: c898435f-e7cd-482c-9fbb-6adbb847449c
---

# PHP 六边形架构落地模式

**Why**: 将传统 MVC 模块重构为 Domain/Application/Infrastructure/Controller 四层，实现依赖倒置和可替换性。

**How to apply**: 每个业务模块按四层模板创建，CI 门禁强制 Domain 零框架依赖。

## 四层模板

```
modules/{Name}/
├── Domain/{Name}.php                   ← 实体 + 值对象, 纯业务规则, 零 IO
├── Domain/{Name}RepositoryInterface.php ← 端口 (内核定义"我需要什么")
├── Application/{Name}UseCase.php        ← 用例编排 (验证→调用端口→返回)
├── Infrastructure/Mysql{Name}Repository.php ← 适配器 (implements 端口)
└── Controller/{Name}Controller.php      ← HTTP 入口 (≤15 行/方法)
```

## 依赖方向 (六边形铁律)

```
Controller → UseCase → RepositoryInterface ← Infrastructure
    (外层)      ↓            (端口)            (适配器)
             Domain
            (内核, 零框架依赖)
```

## 关键模式

### 1. 不可变实体 (Immutable Entity)
```php
class Campaign {
    public function __construct(
        public readonly string $name,     // ← readonly 强制不可变
        public readonly string $status,
    ) {}
    public function transitionTo(string $s): self {  // ← 返回新对象
        return new self(name: $this->name, status: $s);
    }
}
```

### 2. 端口定义 (Repository Interface in Domain)
```php
// Domain/CampaignRepositoryInterface.php
interface CampaignRepositoryInterface {
    public function save(Campaign $c): Campaign;  // ← 内核语言, 非 SQL
}
```

### 3. 适配器 (Infrastructure implements Domain 接口)
```php
class MysqlCampaignRepository implements CampaignRepositoryInterface {
    public function __construct(private DatabaseInterface $db) {}  // ← 依赖抽象
    public function save(Campaign $c): Campaign {
        $stmt = $this->db->prepare('INSERT INTO ...');  // ← SQL 在此, Domain 不知
        return new Campaign(id: $this->db->lastInsertId(), ...);
    }
}
```

### 4. 用例 (无 IO 编排)
```php
class CreateCampaignUseCase {
    public function __construct(private CampaignRepositoryInterface $repo) {}
    public function execute(string $name): Campaign {
        $campaign = new Campaign(name: $name);  // ← 纯 Domain
        return $this->repo->save($campaign);     // ← 调端口
    }
}
```

## 验证清单

- [ ] Domain/ 目录 0 处 `use Illuminate\` / `new mysqli` / `new PDO`
- [ ] Controller 方法 ≤ 15 行
- [ ] RepositoryInterface 定义在 Domain/ (非 Infrastructure/)
- [ ] 换数据库 = 新建 Adapter, Domain 零改动
- [ ] Domain 实体可 `new Xxx(...)` 纯内存实例化 (不连 DB)
- [ ] Domain 测试毫秒级 (0.007s/11 tests)

## 正反例

❌ `new mysqli` 出现在 Domain/Campaign.php → 内核污染
✅ `private DatabaseInterface $db` 在 Infrastructure 层

❌ Controller 里直接 `$db->query('SELECT...')` → 越级调用
✅ Controller 调 UseCase → UseCase 调 RepositoryInterface
