# Converge 目录重构方案

> 版本: v2.0 | 日期: 2026-07-19 | 状态: 🔴 执行中断 — 需审核后继续

---

## 一、现状诊断

### 1.1 当前目录状态（部分已移动）

```
converge/                           ← 项目根 (目标位置)
├── app/                           ← ✅ 已从 data/source/ 移出
├── modules/                       ← ✅ 已从 data/source/ 移出
├── templates/                     ← ✅ 已从 data/source/ 移出
├── public/                        ← ✅ 已从 data/source/ 移出
├── config/                        ← ✅ 已从 data/source/ 移出
├── storage/                       ← ✅ 已从 data/source/ 移出
├── database/                      ← ✅ 已从 data/source/ 移出
├── src/                           ← ⚠️ 旧目录 (重命名前残留)
├── converge-core/                 ← 旧业务中台 (历史)
├── data/
│   └── source/
│       ├── bin/                   ← ⚠️ 仍在 data/source/
│       ├── tools/                 ← ⚠️ 仍在 data/source/
│       ├── vendor/                ← ⚠️ 仍在 data/source/
│       ├── resources/             ← ⚠️ 待删除 (已迁移到 Latte)
│       ├── lang/                  ← ⚠️ 仍在 data/source/
│       ├── node_modules/          ← ⚠️ 仍在 data/source/
│       ├── scripts/               ← 需合并到 repo 根 scripts/
│       ├── docker/                ← 需合并到 repo 根 docker/
│       ├── composer.json          ← ⚠️ 未移动 (PSR-4 路径已失效)
│       ├── composer.lock
│       ├── package.json
│       ├── docker-compose.yml     ← ⚠️ 仍在 data/source/
│       ├── Dockerfile             ← ⚠️ build context 错误
│       └── *.php                  ← 40+ 散落验证脚本
├── scripts/                       ← 根级目录 (data/source/scripts/ 需合并)
├── docker/                        ← 根级目录 (data/source/docker/ 需合并)
├── tests/                         ← 根级目录 (data/source/tests/ 需合并)
├── specs/                         ← 需求规格 (保留)
├── analysis/                      ← 分析报告 (保留)
├── docs/                          ← 文档 (保留)
└── CLAUDE.md
```

### 1.2 当前问题

| # | 问题 | 影响 |
|---|------|------|
| 1 | 🔴 `vendor/composer/autoload_real.php` 缺失 | `bin/tool` 无法运行，所有 PHP 类加载失败 |
| 2 | 🔴 `composer.json` PSR-4 指向 `"app/"` 但 app/ 已不在 `data/source/` | 类加载全断 |
| 3 | 🔴 `bin/tool` 和 `tools/` 仍在 `data/source/` | 工具网格无法自举 |
| 4 | 🟡 `Dockerfile` COPY 路径仍指向 `data/source/` | Docker 构建失败 |
| 5 | 🟡 `docker-compose.yml` 仍在 `data/source/` | build context 混乱 |
| 6 | 🟡 `converge/src/` 旧目录残留 | 与 app/ 重复 |
| 7 | 🟡 `resources/views/` 残留 PHP 视图 | 已迁移到 Latte，仍占空间 |

---

## 二、目标结构

### 2.1 重构后目录树

```
converge/                               ← 项目根 = 所有操作起点
│
├── app/                               ← PHP 应用层 (原 src/)
│   ├── Core/                          ← 核心引擎 (Hooks, ModuleLoader)
│   ├── Foundation/                    ← 基础 (Resilience, Observability)
│   ├── Security/                      ← 安全 (Auth, Csrf, Permission)
│   ├── I18n/                          ← 国际化
│   ├── Tracking/                      ← 追踪
│   ├── Tool/                          ← 工具网格框架
│   └── UI/                            ← 视图引擎 (LatteEngine, ViewContext)
│
├── modules/                           ← 32 六边形模块 (DDD 四层)
│   ├── Campaign/
│   ├── Click/
│   ├── Conversion/
│   └── ...
│
├── templates/                         ← Latte 模板
│   ├── pages/                         ← 页面模板
│   ├── _layouts/                      ← 布局骨架
│   ├── _components/                   ← 可复用组件
│   └── _partials/                     ← 片段
│
├── tools/                             ← 可执行工具 (PHP 类 + #[Tool] 属性)
│   ├── MigrateHybridToLatte.php
│   ├── RenameSrcToApp.php
│   ├── NormalizePaths.php
│   ├── FlattenStructure.php
│   └── ...
│
├── bin/                               ← CLI 入口
│   └── tool                           ← 工具网格统一入口
│
├── public/                            ← Web 根目录
│   ├── index.php                      ← 入口
│   ├── landing.php
│   ├── api-intent.php
│   └── assets/
│
├── config/                            ← 配置文件
│   ├── config.php                     ← APP_ROOT 定义 + 应用配置
│   └── ...
│
├── database/                          ← SQL 迁移
│   └── migrations/
│
├── storage/                           ← 运行时数据
│   ├── tool-registry.json
│   ├── logs/
│   └── cache/
│
├── scripts/                           ← Shell 脚本 (data/source/scripts/ + 根 scripts/ 合并)
│   ├── lib/                           ← 公共函数库
│   ├── pipeline.sh
│   ├── upload.sh
│   ├── deploy-verify.sh
│   ├── docker-up.sh
│   └── README.md
│
├── docker/                            ← Docker 配置 (data/source/docker/ + 根 docker/ 合并)
│   ├── Dockerfile
│   ├── docker-compose.yml
│   ├── docker-compose.dev.yml
│   ├── entrypoint.sh
│   └── verify-deps.php
│
├── tests/                             ← 测试
│   ├── PHPUnit/
│   └── E2E/
│
├── resources/                         ← 静态资源 (非 PHP 视图)
│   └── lang/                          ← 语言文件 (原 data/source/lang/)
│
├── vendor/                            ← Composer 依赖 (从 data/source/ 移出)
│
├── specs/                             ← 需求规格 (保留不动)
├── analysis/                          ← 分析报告 (保留不动)
├── docs/                              ← 文档 (保留不动)
│
├── composer.json                      ← 依赖定义 (从 data/source/ 移出)
├── composer.lock
├── package.json
├── .env
├── .env.docker
├── CLAUDE.md                          ← 项目说明
└── MODULES.md                         ← 模块索引
```

### 2.2 路径常量

```php
// config/config.php (位于 converge/config/)
define('APP_ROOT', dirname(__DIR__));  // → converge/
define('APP_PUBLIC', APP_ROOT . '/public');
define('APP_STORAGE', APP_ROOT . '/storage');
define('APP_CONFIG', APP_ROOT . '/config');
```

### 2.3 对比：重构前 vs 重构后

| 维度 | 重构前 | 重构后 |
|------|------|------|
| 嵌套层级 | `converge/data/source/app/` (4层) | `converge/app/` (2层) |
| 配置位置 | `data/source/config/config.php` | `config/config.php` |
| 入口文件 | `data/source/public/index.php` | `public/index.php` |
| 模板目录 | `data/source/templates/` | `templates/` |
| Composer | `data/source/composer.json` | `composer.json` (项目根) |
| Docker | `data/source/Dockerfile` | `docker/Dockerfile` |
| 脚本 | 散落在 `data/source/scripts/` + `scripts/` | `scripts/` (合并) |
| 工具 | `data/source/bin/tool` | `bin/tool` |
| APP_ROOT | `data/source/` | `converge/` (项目根) |

---

## 三、四个维度

### 3.1 可观察 (Observability)

| 检查点 | 方式 | 通过标准 |
|------|------|------|
| PHP 语法 | `find . -name '*.php' -print0 \| xargs -0 -n1 php -l` | 0 parse error |
| 类加载 | `php bin/tool list` | 工具列表正常输出 |
| HTTP 健康 | `curl -sk localhost/health` | HTTP 200 |
| 页面完整 | `curl -sk localhost/landing.php \| grep '</html>'` | 完整 </html> |

### 3.2 可追溯 (Traceability)

| 检查点 | 方式 | 通过标准 |
|------|------|------|
| Git 历史保留 | `git log --follow app/Core/Hook/Hooks.php` | 完整历史 |
| 路径映射 | `.claude/plans/path-mapping.json` | 旧→新路径映射表 |
| 变更记录 | `git diff --stat main` | 清楚知道哪些文件被移动 |

### 3.3 可验证 (Verifiability)

| 门禁 | 命令 | 通过标准 |
|------|------|:---:|
| G1 语法 | `php -l` 全量扫描 | 0 error |
| G2 工具 | `php bin/tool sync && php bin/tool list` | 工具注册正常 |
| G3 依赖 | `composer dump-autoload --optimize` | 0 fatal |
| G4 Docker | `docker build -t converge:test -f docker/Dockerfile .` | 构建成功 |
| G5 HTTP | `docker compose -f docker/docker-compose.yml up -d && curl localhost/health` | 200 |
| G6 安全 | `curl -sk localhost/.env` | 403 或 404 |

### 3.4 可回滚 (Rollback)

```
整个重构在独立分支 migrate/flatten-structure 上进行
回滚 = git checkout main
数据安全: database/ storage/ 不纳入敏感操作
```

---

## 四、执行计划 (4 Phase)

### Phase 0: 修复自动加载（立即）

> ⏱ 预计 5 分钟 | 风险: 🟢 最低

```
Step 1: 将已移出的目录移回 data/source/ (还原)
  mv converge/app/       → data/source/app/
  mv converge/modules/   → data/source/modules/
  mv converge/templates/ → data/source/templates/
  mv converge/public/    → data/source/public/
  mv converge/config/    → data/source/config/
  mv converge/storage/   → data/source/storage/
  mv converge/database/  → data/source/database/

Step 2: 恢复 composer.json (如果被移动)
  cp composer.json.bak → composer.json  (如存在)

Step 3: 重建 autoload
  cd data/source/
  composer install --no-dev --optimize-autoloader

Step 4: 验证
  php bin/tool list  → 应正常输出工具列表
```

### Phase 1: 清理（低风险）

> ⏱ 预计 15 分钟 | 风险: 🟢 低

```
1.1 删除无用目录
  rm -rf data/source/dist/
  rm -rf data/source/reports/
  rm -rf data/source/test-results/
  rm -rf data/source/analysis/
  rm -rf data/source/contract/
  rm -rf data/source/fabric/
  rm -rf data/source/quality/
  rm -rf data/source/docs/

1.2 删除旧 src/ (已被 app/ 替代)
  rm -rf converge/src/

1.3 删除 resources/views/ 中已被 Latte 替代的 PHP 视图
  逐文件确认 → 删除

1.4 验证
  php bin/tool list ✅
  composer dump-autoload ✅
  git status 确认变更范围
```

### Phase 2: 提级（中等风险）

> ⏱ 预计 30 分钟 | 风险: 🟡 中

```
2.1 移动核心目录 data/source/* → converge/
  mv data/source/app/      → converge/app/
  mv data/source/modules/  → converge/modules/
  mv data/source/templates/→ converge/templates/
  mv data/source/tools/    → converge/tools/
  mv data/source/bin/      → converge/bin/
  mv data/source/public/   → converge/public/
  mv data/source/config/   → converge/config/
  mv data/source/storage/  → converge/storage/
  mv data/source/database/ → converge/database/
  mv data/source/vendor/   → converge/vendor/
  mv data/source/lang/     → converge/resources/lang/
  mv data/source/composer.json → converge/composer.json
  mv data/source/composer.lock → converge/composer.lock

2.2 合并目录
  合并 data/source/scripts/ → converge/scripts/ (不覆盖已有)
  合并 data/source/docker/  → converge/docker/  (不覆盖已有)

2.3 更新配置文件
  config/config.php: APP_ROOT = dirname(__DIR__) → __DIR__
  composer.json: PSR-4 路径不变 (composer.json 在根，app/ 相对路径正确)
  bin/tool: autoload 路径 ../vendor/ → ./vendor/ (在根目录)
  docker/Dockerfile: COPY . → COPY . (build context 已是根)
  docker/docker-compose.yml: build context 已是 .
  docker/entrypoint.sh: 更新路径
  .dockerignore: 更新排除规则

2.4 删除 data/source/ 剩余的 node_modules/ → 移到根
  mv data/source/node_modules/ → converge/node_modules/ (如需要)
  mv data/source/package.json  → converge/package.json
  mv data/source/package-lock.json → converge/package-lock.json

2.5 验证 G1-G6
  G1: 全量 php -l ✅
  G2: php bin/tool sync && php bin/tool list ✅
  G3: composer dump-autoload ✅
  G4: docker build ✅
  G5: curl localhost/health ✅
  G6: curl localhost/.env → 403 ✅
```

### Phase 3: 验证与清理（收尾）

> ⏱ 预计 15 分钟 | 风险: 🟢 低

```
3.1 清理空的 data/source/ 目录
  确认所有必要文件已移出后删除

3.2 服务器 Docker 重建部署
  docker build -t converge:latest -f docker/Dockerfile .
  docker compose -f docker/docker-compose.yml up -d

3.3 部署后八步验证
  bash scripts/deploy-verify.sh

3.4 Git 提交
  git add -A
  git commit -m "refactor: 目录重构 — 扁平化 converge/ 根目录"
```

---

## 五、风险矩阵

| 风险 | 概率 | 影响 | 缓解措施 |
|------|:---:|:---:|------|
| autoload 断裂 | 高 | 高 | Phase 0 先修复，composer install |
| Docker build 失败 | 中 | 高 | 本地先测，COPY 路径确认 |
| 文件移动丢失 | 低 | 高 | Git 跟踪，每步验证 |
| 路径引用遗漏 | 中 | 中 | NormalizePaths 工具全局扫描 |
| 服务器部署中断 | 低 | 中 | Docker 镜像原子切换 |
| `bin/tool` 自举失败 | 中 | 高 | Phase 2.3 先更新 autoload 路径再验证 |

---

## 六、路径映射表 (旧 → 新)

| 旧路径 | 新路径 | 类型 |
|------|------|:---:|
| `data/source/app/` | `app/` | 移动 |
| `data/source/src/` → `app/` | `app/` | 已重命名 |
| `data/source/modules/` | `modules/` | 移动 |
| `data/source/templates/` | `templates/` | 移动 |
| `data/source/tools/` | `tools/` | 移动 |
| `data/source/bin/` | `bin/` | 移动 |
| `data/source/public/` | `public/` | 移动 |
| `data/source/config/` | `config/` | 移动 |
| `data/source/storage/` | `storage/` | 移动 |
| `data/source/database/` | `database/` | 移动 |
| `data/source/vendor/` | `vendor/` | 移动 |
| `data/source/lang/` | `resources/lang/` | 移动 |
| `data/source/composer.json` | `composer.json` | 移动 |
| `data/source/composer.lock` | `composer.lock` | 移动 |
| `data/source/package.json` | `package.json` | 移动 |
| `data/source/Dockerfile` | `docker/Dockerfile` | 合并 |
| `data/source/docker-compose.yml` | `docker/docker-compose.yml` | 合并 |
| `data/source/scripts/*` | `scripts/` | 合并 |
| `data/source/resources/views/` | ❌ 删除 | 已迁移 Latte |
| `data/source/node_modules/` | `node_modules/` | 移动 |
| `converge/src/` | ❌ 删除 | 已被 app/ 替代 |
| `converge/converge-core/` | ❌ 删除 | 历史遗留 |
| `data/source/.env` | `.env` | 移动 |
| `data/source/.env.docker` | `.env.docker` | 移动 |

---

## 七、审核清单

请逐项审核，通过打 ✅：

### 目录结构
- [ ] 目标目录结构是否合理？（见第二节）
- [ ] `app/` vs `src/` — 命名是否确认？
- [ ] `resources/lang/` 路径是否合适？
- [ ] `docker/` 子目录是否满意？还是 Docker 文件放根？

### 执行计划
- [ ] Phase 0 还原+修复 是否可以接受暂时还原？
- [ ] Phase 1 删除清单是否完整？有无遗漏？
- [ ] Phase 2 移动和合并顺序是否合理？
- [ ] Phase 3 收尾验证是否足够？

### 风险
- [ ] 风险矩阵是否覆盖所有可能问题？
- [ ] 回滚策略是否可靠？

### 其他
- [ ] APP_ROOT 常量值是否正确？
- [ ] Docker build context 路径是否正确？
- [ ] 服务器部署流程是否需要调整？

---

> 📄 审核完成后回复"批准"或具体修改意见，我立即执行。
