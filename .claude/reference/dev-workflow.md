# Content-OS 开发工作流详解

> 从需求到提交的完整流程。CLAUDE.md 有编码顺序，这里展开每一步。
> 目录结构 → 编码 → Hook → 数据库 → 验证 → 部署

## 项目目录结构

```
content-os/
├── modules/                  ← 业务模块 (每个模块四层六边形)
│   └── {Name}/
│       ├── Domain/           ← 实体 + 端口接口 (零 IO)
│       ├── Application/      ← UseCase 编排
│       ├── Infrastructure/   ← 适配器 (MysqlXxxRepository)
│       ├── Controller/       ← HTTP 入口 (≤15行/方法)
│       └── bootstrap.php     ← Hook 注册 + 路由
├── src/                      ← 应用层共享代码 (App\ 命名空间)
│   └── api.js                ← 前端 API 通信层
├── public/                   ← Web 入口 + 静态资源
│   ├── index.php             ← 前端控制器
│   └── assets/               ← 编译后的 CSS/JS
├── config/                   ← 环境配置
│   └── env.php               ← 常量定义 (DB/APP_URL等)
├── tests/                    ← 测试
│   ├── Unit/                 ← 单元测试 (PHPUnit)
│   └── E2E/                  ← 端到端测试 (Playwright)
├── database/                 ← 数据库迁移
│   └── migrations/           ← SQL 文件 (按序号命名)
├── .claude/                  ← Claude 配置
│   ├── prd.md                ← 需求文档 (权威来源)
│   └── reference/            ← 详细参考文档
│       ├── capability-catalog.md  ← converge-core 能力目录
│       ├── design-tokens.md       ← 设计令牌参考
│       └── dev-workflow.md        ← 本文件
├── .github/workflows/        ← CI/CD 流水线
└── vendor/                   ← Composer 依赖
```

## 一、新增模块

```bash
# 生成骨架 (Domain/Application/Infrastructure/Controller + bootstrap.php + test)
bash ../converge-core/scripts/dev/module-scaffold.sh {Name}
```

## 二、编码顺序 (严格，不可跳过)

```
1. Domain/{Name}.php
   → 实体类，public readonly 属性
   → 状态常量 + VALID_TRANSITIONS 矩阵
   → 状态转换方法返回 new self()
   → 零 IO (不出现 mysqli/PDO/Illuminate)

2. Domain/{Name}RepositoryInterface.php
   → save() + find*() 方法签名
   → 只依赖 Domain 实体类型

3. Application/{Name}UseCase.php
   → 构造注入 RepositoryInterface
   → execute() 编排业务流程
   → 不直接操作数据库

4. Infrastructure/Mysql{Name}Repository.php
   → implements RepositoryInterface
   → 构造注入 DatabaseInterface
   → 参数化 SQL，hydrate() 转换行→实体

5. Controller/{Name}Controller.php
   → 方法 ≤15 行
   → 提取参数 → 调 UseCase → 返回 JSON
   → 构造注入 DatabaseInterface

6. bootstrap.php
   → 注册 Hooks (菜单+路由)
   → 不写业务逻辑
```

## 三、Hook 注册模板

```php
<?php
// modules/{Name}/bootstrap.php
use Converge\Core\Hook\Hooks;

// 注册 Dock 菜单
Hooks::addFilter('ui.dock.panels', function(array $panels): array {
    $panels[] = ['id' => 'xxx', 'label' => '名称', 'icon' => '📝', 'order' => 10];
    return $panels;
});

// 注册页面路由
Hooks::addAction('page.xxx', function() {
    // 渲染页面
});
```

## 四、数据库迁移

```sql
-- database/migrations/001_create_topics.sql
CREATE TABLE IF NOT EXISTS topics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    keywords JSON,
    status ENUM('draft','published','archived') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### 约定
- 表名: 小写+下划线，复数 (topics, contents, users)
- 主键: `id INT AUTO_INCREMENT PRIMARY KEY`
- 时间戳: `created_at` + `updated_at` (MySQL 自动维护)
- 状态字段: ENUM 列出所有合法值
- JSON: MySQL JSON 类型，不序列化为 TEXT
- 迁移文件: `database/migrations/` 按 001/002 序号命名

## 五、验证流程

```bash
# 写完模块立即验证 (秒级反馈)
php ../converge-core/scripts/dev/verify-modules.php

# 架构门禁 (提交前)
bash ../converge-core/scripts/ci/enforce-architecture.sh

# 单元测试
php vendor/bin/phpunit

# 完整检查清单
□ verify-modules.php → 0 failures
□ enforce-architecture.sh → 0 阻断
□ Domain 层 0 处 IO 调用
□ 跨模块仅通过 Hooks 通信
□ 文件 ≤150 行
```

## 六、完整示例 (Product 模块)

```php
// 1. Domain/Product.php
class Product {
    public const DRAFT = 'draft', ACTIVE = 'active';
    public function __construct(
        public readonly string $name,
        public readonly float $price,
        public readonly string $status = self::DRAFT,
    ) {}
    public function activate(): self {
        if ($this->status !== self::DRAFT) throw new \DomainException('只有草稿可激活');
        return new self(name: $this->name, price: $this->price, status: self::ACTIVE);
    }
}

// 2. Domain/ProductRepositoryInterface.php
interface ProductRepositoryInterface {
    public function save(Product $p): Product;
}

// 3. Application/CreateProductUseCase.php
class CreateProductUseCase {
    public function __construct(private ProductRepositoryInterface $repo) {}
    public function execute(string $name, float $price): Product {
        return $this->repo->save(new Product(name: $name, price: $price));
    }
}

// 4. Infrastructure/MysqlProductRepository.php
class MysqlProductRepository implements ProductRepositoryInterface {
    public function __construct(private \Converge\Contracts\DatabaseInterface $db) {}
    public function save(Product $p): Product {
        $this->db->prepare('INSERT INTO products (name, price, status) VALUES (?, ?, ?)')
            ->execute([$p->name, $p->price, $p->status]);
        return new Product(name: $p->name, price: $p->price, status: $p->status,
            id: (int)$this->db->lastInsertId());
    }
}
```
