# Converge 目录迁移方案 — 注册表驱动·渐进式·可回滚

> 版本: v3.0 | 日期: 2026-07-19 | 状态: 审核中
> 核心理念: 迁移不是文件搬运，是治理过程

---

## 一、问题诊断

### 1.1 为什么之前的方案失败了

| 失败模式 | 根因 | 行业反例 |
|------|------|------|
| autoload 断裂 | `composer.json` 移到新位置但 `app/` 未同步移动 | 缺少原子性 — Go 用 `replace` 指令保证新旧共存 |
| 工具自举崩溃 | PHP 脚本移动自己正在运行的 `app/` 目录 | 没有 indirection 层 — 需要先改注册表再动文件 |
| 路径引用遗漏 | 485 文件批量替换，无逐文件验证 | 没有渐进式逐模块迁移 — 大爆炸式搬家的必然结果 |
| 回滚靠 git checkout | 30+ 目录移动后的回滚 == 丢失中间的所有工作 | 没有操作记录 — 注册表天然提供每步快照 |

### 1.2 行业参照

```
Go 模块迁移:   go.mod replace 指令 → 旧路径映射到新路径 → 逐模块更新 → 删除 replace
Monorepo 迁移: 目录映射表 → git filter-repo 批处理 → CODEOWNERS 治理
前端巨石拆分: 模块契约 + 目录规范 → 逐模块迁移 → 接口不变
PHP 部署:     symlink atomic swap → Opcache resolve_symlinks → 零停机
```

**共同点**: 都有一层 "indirection"（间接层）——先改逻辑引用，再动物理文件。

---

## 二、架构设计

### 2.1 三组件协同

```
                    ┌─────────────────────────┐
                    │  Migration Registry      │
                    │  storage/migration-      │
                    │  registry.json           │
                    │                          │
                    │  { mappings, status,     │
                    │    history, rollback }    │
                    └──────┬───────────────────┘
                           │
              ┌────────────┼────────────┐
              │            │            │
              ▼            ▼            ▼
    ┌─────────────┐ ┌───────────┐ ┌──────────────┐
    │ PathResolver │ │ Migration │ │ MigrationGate │
    │ (运行时解析)  │ │ Tool      │ │ (验证门禁)    │
    │              │ │ (执行器)   │ │              │
    │ resolve()    │ │ dryRun()  │ │ G1-G6 check  │
    │ remap()      │ │ execute() │ │ per-module   │
    │ verify()     │ │ rollback()│ │ verify       │
    └─────────────┘ └───────────┘ └──────────────┘
```

### 2.2 核心概念：虚拟迁移 → 物理迁移

```
Phase A: 虚拟迁移 (零风险)
  ① 更新 registry.json 中的 target 路径
  ② PathResolver 自动将旧路径映射到新路径
  ③ 运行全量测试 → 验证新结构可行性
  ④ 如果失败 → 改 registry.json 回退，零文件移动

Phase B: 物理迁移 (逐模块)
  ① 选一个独立模块 (如 Analytics)
  ② Migration Tool --dry-run → 预览
  ③ Migration Tool execute → 移动文件 + 更新引用
  ④ MigrationGate verify → G1-G6
  ⑤ 通过 → registry.status = "done"
  ⑥ 失败 → rollback → registry.status = "rolled_back"

Phase C: 收尾
  ① 所有模块迁移完成
  ② 删除 PathResolver (不再需要)
  ③ 归档 registry.json
```

---

## 三、迁移注册表设计

### 3.1 Schema (`storage/migration-registry.json`)

```json
{
  "version": "1.0",
  "created_at": "2026-07-19T10:00:00+08:00",
  "source_root": "data/source/",
  "target_root": ".",
  "batches": [
    {
      "id": "batch-01-core-config",
      "label": "核心配置",
      "priority": 1,
      "status": "pending",
      "items": [
        {
          "source": "data/source/composer.json",
          "target": "composer.json",
          "type": "file",
          "status": "pending"
        },
        {
          "source": "data/source/config/",
          "target": "config/",
          "type": "directory",
          "status": "pending"
        }
      ],
      "post_hooks": ["composer dump-autoload"],
      "rollback_hooks": ["git checkout -- composer.json config/"]
    },
    {
      "id": "batch-02-vendor",
      "label": "Composer 依赖",
      "priority": 2,
      "status": "pending",
      "depends_on": ["batch-01-core-config"],
      "items": [
        {
          "source": "data/source/vendor/",
          "target": "vendor/",
          "type": "directory",
          "status": "pending"
        }
      ],
      "post_hooks": ["composer dump-autoload --optimize"],
      "rollback_hooks": ["mv vendor/ data/source/vendor/"]
    },
    {
      "id": "batch-03-app",
      "label": "应用核心 (app/)",
      "priority": 3,
      "status": "pending",
      "depends_on": ["batch-02-vendor"],
      "items": [
        {
          "source": "data/source/app/",
          "target": "app/",
          "type": "directory",
          "status": "pending"
        }
      ],
      "path_updates": {
        "pattern": "__DIR__ . '/../app/",
        "replacement": "APP_ROOT . '/app/",
        "files": "*.php"
      },
      "post_hooks": ["php bin/tool sync", "composer dump-autoload"],
      "rollback_hooks": ["mv app/ data/source/app/", "composer dump-autoload"]
    },
    {
      "id": "batch-04-modules",
      "label": "业务模块 (modules/)",
      "priority": 4,
      "status": "pending",
      "depends_on": ["batch-03-app"],
      "items": [
        {
          "source": "data/source/modules/Analytics/",
          "target": "modules/Analytics/",
          "type": "directory",
          "status": "pending"
        }
      ],
      "sub_batches": "每个模块独立一行，逐模块迁移",
      "post_hooks": ["php bin/tool sync"],
      "rollback_hooks": ["mv modules/X/ data/source/modules/X/"]
    },
    {
      "id": "batch-05-templates",
      "label": "模板 (templates/)",
      "priority": 5,
      "status": "pending",
      "depends_on": ["batch-03-app"],
      "items": [
        {
          "source": "data/source/templates/",
          "target": "templates/",
          "type": "directory",
          "status": "pending"
        }
      ]
    },
    {
      "id": "batch-06-public",
      "label": "Web 入口 (public/)",
      "priority": 6,
      "status": "pending",
      "depends_on": ["batch-03-app"],
      "items": [
        {
          "source": "data/source/public/",
          "target": "public/",
          "type": "directory",
          "status": "pending"
        }
      ]
    },
    {
      "id": "batch-07-rest",
      "label": "其余目录",
      "priority": 7,
      "status": "pending",
      "depends_on": ["batch-04-modules", "batch-05-templates", "batch-06-public"],
      "items": [
        {"source": "data/source/tools/", "target": "tools/", "type": "directory", "status": "pending"},
        {"source": "data/source/bin/", "target": "bin/", "type": "directory", "status": "pending"},
        {"source": "data/source/storage/", "target": "storage/", "type": "directory", "status": "pending"},
        {"source": "data/source/database/", "target": "database/", "type": "directory", "status": "pending"},
        {"source": "data/source/lang/", "target": "resources/lang/", "type": "directory", "status": "pending"}
      ]
    },
    {
      "id": "batch-08-merge",
      "label": "合并 scripts/ 和 docker/",
      "priority": 8,
      "status": "pending",
      "depends_on": ["batch-07-rest"],
      "items": [
        {"source": "data/source/scripts/", "target": "scripts/", "type": "merge", "status": "pending"},
        {"source": "data/source/docker/", "target": "docker/", "type": "merge", "status": "pending"}
      ]
    },
    {
      "id": "batch-09-cleanup",
      "label": "清理残留",
      "priority": 9,
      "status": "pending",
      "depends_on": ["batch-08-merge"],
      "items": [
        {"source": "data/source/", "target": null, "type": "verify_empty_then_remove", "status": "pending"},
        {"source": "resources/views/", "target": null, "type": "delete", "status": "pending", "note": "已迁移到 Latte"},
        {"source": "src/", "target": null, "type": "delete", "status": "pending", "note": "已被 app/ 替代"}
      ]
    }
  ],
  "history": [],
  "stats": {
    "total_items": 0,
    "completed": 0,
    "failed": 0,
    "rolled_back": 0
  }
}
```

### 3.2 状态机

```
pending → in_progress → completed
                      → failed → rolled_back → pending (retry)
                      
每个 batch 独立状态，互不阻塞。
batch 有 depends_on → 前置 batch 必须 completed 才能开始。
```

---

## 四、PathResolver 设计

### 4.1 核心逻辑

```php
// app/Core/Migration/PathResolver.php
namespace Converge\Core\Migration;

class PathResolver
{
    /** @var array<string, string> source => target */
    private array $mappings = [];

    public function __construct(string $registryPath)
    {
        $registry = json_decode(file_get_contents($registryPath), true);
        foreach ($registry['batches'] as $batch) {
            if ($batch['status'] !== 'completed') continue;
            foreach ($batch['items'] as $item) {
                if ($item['status'] === 'completed') {
                    $this->mappings[$item['source']] = $item['target'];
                }
            }
        }
    }

    /**
     * 解析路径：如果已迁移，返回新路径；否则返回原路径。
     */
    public function resolve(string $path): string
    {
        foreach ($this->mappings as $source => $target) {
            if (str_starts_with($path, $source)) {
                return $target . substr($path, strlen($source));
            }
        }
        return $path;
    }

    /**
     * 反向解析（用于调试）：给定新路径，找到旧路径。
     */
    public function reverse(string $newPath): string
    {
        foreach ($this->mappings as $source => $target) {
            if (str_starts_with($newPath, $target)) {
                return $source . substr($newPath, strlen($target));
            }
        }
        return $newPath;
    }

    /**
     * 验证所有已迁移路径的文件实际存在。
     */
    public function verify(): array
    {
        $errors = [];
        foreach ($this->mappings as $source => $target) {
            if (!file_exists(APP_ROOT . '/' . $target)) {
                $errors[] = "Missing: $target (was: $source)";
            }
        }
        return $errors;
    }
}
```

### 4.2 集成方式 (轻量，不改现有架构)

```php
// bootstrap.php — 启动时注册
$resolver = new \Converge\Core\Migration\PathResolver(
    APP_ROOT . '/storage/migration-registry.json'
);

// 仅用于 include/require 路径解析
// PSR-4 autoload 由 Composer 管理 (dump-autoload 后自动正确)
// 所以 PathResolver 主要用于非类的文件引用：模板、配置、脚本
```

### 4.3 何时不需要 PathResolver

| 引用方式 | 需要 PathResolver? | 原因 |
|------|:---:|------|
| PSR-4 类加载 | ❌ | Composer dump-autoload 自动正确 |
| `APP_ROOT . '/config/...'` | ❌ | APP_ROOT 常量自动适配 |
| `include __DIR__ . '/../templates/...'` | ❌ | 相对路径自动适配 |
| 硬编码 `data/source/xxx` | ✅ | 唯一需要 PathResolver 的场景 |
| Shell 脚本中的路径 | ❌ | 用 APP_ROOT 或相对路径 |

**结论**: 在 Converge 中，因为已经完成了 `APP_ROOT` 路径标准化，PathResolver 的职责极轻——主要用于验证迁移完整性，而非运行时路径转换。

---

## 五、迁移工具设计

### 5.1 `tools/MigrateDirectory.php`

```php
#[Tool(
    name: 'migrate-directory',
    description: '注册表驱动的目录迁移 — dry-run/execute/rollback/status',
    category: 'migration',
    parameters: [
        'batch' => 'string (batch ID or "all")',
        'action' => 'string (dry-run|execute|rollback|status|verify)',
    ],
)]
class MigrateDirectory implements ToolInterface
{
    public function dryRun(array $params): ToolResult
    {
        $batchId = $params['batch'] ?? 'all';
        $plan = $this->buildPlan($batchId);
        // 输出: 将移动 X 文件，更新 Y 引用，影响 Z 模块
        return ToolResult::ok(['plan' => $plan]);
    }

    public function execute(array $params): ToolResult
    {
        $batchId = $params['batch'];
        $batch = $this->loadBatch($batchId);
        
        // 1. 检查依赖
        // 2. 快照当前状态 → history
        // 3. 逐 item 移动
        // 4. 更新路径引用 (仅限该 batch 范围内的文件)
        // 5. 更新 registry.json status
        // 6. 运行 post_hooks
        // 7. 验证
        
        return ToolResult::ok(['moved' => count($items), 'batch' => $batchId]);
    }

    public function rollback(array $params): ToolResult
    {
        $batchId = $params['batch'];
        // 1. 从 history 读取快照
        // 2. 逐 item 反向移动
        // 3. 运行 rollback_hooks
        // 4. 更新 registry.json status = "rolled_back"
        
        return ToolResult::ok(['rolled_back' => $batchId]);
    }

    public function status(array $params): ToolResult
    {
        // 读取 registry.json → 彩色输出每 batch 状态
        return ToolResult::ok(['registry' => $this->loadRegistry()]);
    }
}
```

### 5.2 CLI 使用

```bash
# 查看整体状态
php bin/tool run migrate-directory --action=status

# 预览第一批
php bin/tool run migrate-directory batch-01-core-config --dry-run

# 执行第一批
php bin/tool run migrate-directory batch-01-core-config

# 验证
php bin/tool run migrate-directory batch-01-core-config --action=verify

# 回滚 (如果失败)
php bin/tool run migrate-directory batch-01-core-config --action=rollback

# 逐批推进
php bin/tool run migrate-directory batch-02-vendor
php bin/tool run migrate-directory batch-03-app
# ...
```

---

## 六、验证门禁 (MigrationGate)

### 6.1 每 batch 验证

```
batch 完成后自动运行:
  G1: php -l 全量语法 (仅扫描本 batch 涉及的文件)
  G2: composer dump-autoload → 0 fatal
  G3: php bin/tool list → 工具网格正常
  G4: 抽查 3 个关键页面 require → 无 fatal error
  
任一 fail → batch 标记 failed → 自动触发 rollback
```

### 6.2 全量验证 (所有 batch 完成后)

```
G1: find . -name '*.php' | xargs -n1 php -l → 0 errors
G2: php bin/tool sync && php bin/tool list → 工具正常
G3: composer dump-autoload --optimize → 无 error
G4: docker build -t converge:test -f docker/Dockerfile . → 成功
G5: docker compose up -d && curl localhost/health → 200
G6: curl localhost/landing.php | grep '</html>' → 完整 HTML
```

---

## 七、执行策略

### 7.1 从最独立的模块开始

```
依赖分析结果:
  batch-01 (config)     ← 无依赖，所有模块依赖它
  batch-02 (vendor)     ← 依赖 batch-01
  batch-03 (app/Core)   ← 被所有模块依赖，风险最高 → 放在早期但有充分验证
  batch-04 (modules/*)  ← 相互独立 → 逐模块迁移，每模块独立验证
    modules/Analytics/     (0 跨模块引用)
    modules/ABTest/        (1 跨模块引用)
    modules/Campaign/      (核心，被 5+ 模块引用) ← 放最后
  batch-05 (templates)  ← 依赖 app/
  batch-06 (public)     ← 依赖 app/
  batch-07 (tools/bin)  ← 自举 → 特殊处理
  batch-08 (merge)      ← 合并，无风险
  batch-09 (cleanup)    ← 删除，有 git 保护
```

### 7.2 核心模块放最后

```
modules/Campaign/ — 被 15+ 文件引用 → 最后迁移
modules/Conversion/ — 被 10+ 文件引用 → 倒数第二
modules/Click/ — 被 8+ 文件引用 → 倒数第三
```

### 7.3 自举问题处理

`tools/` 和 `bin/` 目录迁移时，工具正在运行。解决方案：

```
① 复制 (不移动) tools/ → converge/tools/
② 更新 registry.status = "completed"  
③ bin/tool 从新位置运行验证
④ 验证通过 → 删除 data/source/tools/
⑤ 同样的方式处理 bin/
```

---

## 八、与现有方案的对比

| 维度 | 旧方案 (PowerShell 脚本) | 新方案 (注册表驱动) |
|------|------|------|
| 原子性 | ❌ 全量搬，失败卡中间 | ✅ 每 batch 独立，失败只影响该 batch |
| 可观测 | ❌ 控制台输出，丢了就没了 | ✅ registry.json 持久化每步状态 |
| 可回滚 | ❌ git checkout 整个仓库 | ✅ 逐 batch 回滚，不影响其他 batch |
| 可验证 | ❌ 搬完再查错 | ✅ 每 batch 后自动 G1-G4 |
| 自举安全 | ❌ 搬自己的运行目录 | ✅ 复制→验证→删除 |
| 渐进式 | ❌ 一次搬 10 目录 | ✅ 9 个 batch，从最简单开始 |

---

## 九、审查清单

- [ ] 注册表结构是否合理？batch 划分是否恰当？
- [ ] PathResolver 职责是否过重？（Converge 场景下极轻，可以接受）
- [ ] 依赖分析是否正确？哪些模块应该先迁移？
- [ ] 自举方案（tools/bin 复制而非移动）是否可行？
- [ ] 每 batch 后验证是否足够？
- [ ] 回滚策略是否覆盖所有 batch？

---

## 十、可观察·可追溯·可验证

### 10.1 可观察 (Observability) — 迁移过程透明可见

```
┌─ 实时状态面板 ─────────────────────────────────┐
│                                                │
│  batch-01 (config)     ████████████ ✅ done     │
│  batch-02 (vendor)     ████████████ ✅ done     │
│  batch-03 (app/Core)   ██████░░░░░░ ⏳ running  │
│    └─ app/Core/Hook/   ████████░░ ✅ done       │
│    └─ app/Core/Module/ ████░░░░░░ ⏳ moving...  │
│  batch-04 (modules)    ░░░░░░░░░░░░ 📋 pending  │
│  batch-05 (templates)  ░░░░░░░░░░░░ 📋 pending  │
│  ...                                            │
└────────────────────────────────────────────────┘
```

**实现**:

| 维度 | 方式 | 数据 |
|------|------|------|
| 进度可视化 | `php bin/tool run migrate-directory --action=status` | 彩色终端输出，每 batch 百分比 |
| 文件级追踪 | `--verbose` 模式输出每个文件的 `from → to` | stdin 实时流 |
| 变更预览 | `--dry-run` 展示将发生的所有变更，不计入 history | 变更清单 |
| 健康检查 | 每 batch 后自动 G1-G4，结果即时显示 | pass/fail 信号 |
| 日志持久化 | `storage/logs/migration-{date}.log` — 所有操作完整记录 | 结构化日志 |

### 10.2 可追溯 (Traceability) — 每一步都有因果链

```
每条操作记录包含:
  {
    "timestamp": "2026-07-19T14:32:01+08:00",
    "batch_id": "batch-03-app",
    "item": "data/source/app/Core/ → app/Core/",
    "action": "move",
    "operator": "migrate-directory tool v1.0",
    "before_snapshot": {
      "path": "data/source/app/Core/",
      "size_bytes": 245760,
      "file_count": 48,
      "sha256": "a1b2c3..."
    },
    "after_snapshot": {
      "path": "app/Core/",
      "size_bytes": 245760,
      "file_count": 48,
      "sha256": "a1b2c3..."
    },
    "path_updates": [
      {"file": "public/index.php", "old": "require __DIR__.'/../app/...'", "new": "require APP_ROOT.'/app/...'"},
      {"file": "bin/tool", "old": "...", "new": "..."}
    ],
    "verification": {
      "G1_syntax": "pass",
      "G2_autoload": "pass", 
      "G3_tool_mesh": "pass",
      "G4_http": "pass"
    },
    "duration_ms": 1234
  }
```

**实现**:

| 维度 | 方式 | 数据位置 |
|------|------|------|
| 操作审计链 | 每条操作 append 到 `registry.history[]` | `storage/migration-registry.json` |
| 文件指纹 | 移动前后 SHA256 校验 | `registry.history[].before_snapshot.sha256` |
| 路径映射 | 旧路径→新路径完整记录 | `registry.batches[].items[]` |
| 谁在何时做了什么 | operator + timestamp | 每条 history 记录 |
| Git 关联 | 每 batch 完成自动 commit，commit message 含 batch ID | `git log --grep="batch-03"` |

### 10.3 可验证 (Verifiability) — 每步都有自动化证明

```
验证分层:

L1: 即时验证 (每文件移动后)
  ├─ file_exists(new_path)  → 文件确实在新位置
  ├─ sha256(new) == sha256(old) → 内容完整无损
  └─ !file_exists(old_path) → 旧位置已清理 (除非是 copy 模式)

L2: Batch 验证 (每 batch 完成后)
  ├─ G1: php -l (batch 涉及的所有 PHP 文件)
  ├─ G2: composer dump-autoload --optimize (0 fatal)
  ├─ G3: php bin/tool list (工具网格正常)
  └─ G4: php -r "require 'public/index.php';" (入口文件可加载)

L3: 跨 Batch 验证 (所有 batch 完成后)
  ├─ G5: docker build -t converge:test .
  ├─ G6: curl localhost/health → 200
  └─ G7: curl localhost/landing.php | grep '</html>'

L4: 数据验收 (M3 公式)
  ├─ 文件数量: 移动前 = 移动后
  ├─ 总大小: 移动前 = 移动后
  └─ 完整性: 100% 文件 SHA256 匹配
```

**实现**:

| 维度 | 方式 | 通过标准 |
|------|------|:---:|
| 内容完整性 | SHA256 before/after 对比 | 100% 匹配 |
| 语法正确性 | `php -l` 批量扫描 | 0 parse error |
| 功能可用性 | 工具网格 + 入口文件加载 | 0 fatal error |
| 构建可重复 | Docker build | 构建成功 |
| 运行时正确 | HTTP health check | 200 OK |

### 10.4 三支柱交叉验证矩阵

```
可观察 → 产生状态数据 → 可追溯 (history 记录)
   ↓                        ↓
可验证 (SHA256/G1-G7)   ←  每步操作有证据
   ↓                        ↓
   └── 三个全绿 → batch completed ──┘

具体检查:
  观察 × 追溯: status 输出与 registry.json 完全一致？ → verify-status-consistency
  追溯 × 验证: history 中每条的 verification 都是 pass？ → verify-history-clean
  验证 × 观察: dry-run 预览与实际执行结果一致？ → verify-dryrun-match
```

---

## 十一、交付清单

### 需要创建的文件

| 文件 | 用途 | 大小 |
|------|------|:---:|
| `storage/migration-registry.json` | 迁移注册表 (权威数据源) | ~5KB |
| `app/Core/Migration/PathResolver.php` | 路径解析器 | ~60行 |
| `tools/MigrateDirectory.php` | 迁移工具 (#[Tool]) | ~200行 |
| `scripts/enforce-migration.sh` | 迁移门禁 (G1-G7) | ~50行 |

### 不需要修改的文件

- `config/config.php` — APP_ROOT = `dirname(__DIR__)` 自动适配
- `bin/tool` — `../vendor/autoload.php` 相对路径自动适配
- `composer.json` — PSR-4 相对路径自动适配
- 已有的 4 个工具 — 不受影响

### 执行时间估算

| Batch | 内容 | 时间 |
|------|------|:---:|
| batch-01 | config + composer.json | 1 min |
| batch-02 | vendor/ (composer install) | 3 min |
| batch-03 | app/Core/ | 2 min |
| batch-04 | modules/* (32 模块逐批) | 10 min |
| batch-05-08 | templates/public/tools/bin/merge | 5 min |
| batch-09 | 清理 + 全量验证 G1-G7 | 5 min |
| **总计** | | **~25 min** |

---

> 审核意见: _______________
