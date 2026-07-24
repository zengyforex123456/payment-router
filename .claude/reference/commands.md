# Converge 命令参考

> 所有开发和部署命令速查表

## 本地开发

```bash
php -S localhost:8080 -t public/
composer dump-autoload -d data/source/
```

## 测试

```bash
php vendor/bin/phpunit -c tests/phpunit.xml    # PHPUnit
npx playwright test                              # E2E
```

## 门禁

```bash
bash scripts/enforce-architecture.sh             # 架构门禁
php ../converge-core/scripts/dev/verify-modules.php  # 模块契约
php bin/tool run enforce-ui-architecture         # UI 架构门禁 (全量)
php bin/tool run enforce-ui-architecture --staged  # UI 架构门禁 (仅 staged)
```

## Docker

```bash
docker compose up -d                             # 生产部署
docker compose -f docker-compose.dev.yml up -d   # 本地开发
pwsh scripts/dev-start.ps1                       # 一键开发环境 (自动端口检测)
bin/tool run dev-start                           # 同上，通过 Tool 网格
bash scripts/docker-up.sh                        # 一键部署
```

## 部署

```bash
# 默认方案 = upload.sh
bash scripts/upload.sh root@your-server.com      # 上传+校验+原子切换+八步验证
bash scripts/upload.sh root@your-server.com --rsync  # 增量续传 (最快)
bash scripts/upload.sh --rollback                # 回滚到上一版本
bash scripts/deploy-verify.sh                    # 仅验证 (八步全链路)

# 新项目接入
cp scripts/deploy.template.conf scripts/deploy.conf  # 改 5 个值
```

## 模块

```bash
# ModuleLoader 自动扫描 modules/*/bootstrap.php
# 添加新 PHP 文件后:
php scripts/gen-classmap.php --write   # 重建 composer classmap
composer dump-autoload                  # 刷新 PSR-4
```

## 代码生成

```bash
# Latte 模板编译检查
php data/source/scripts/test-latte-compile.php

# Latte 语法修复 (idempotent)
php data/source/scripts/fix-latte-script-syntax.php

# Latte 编译错误修复
php data/source/scripts/fix-latte-compile-errors.php

# 模板 L3 自证标记注入
php scripts/inject-template-assertions.php

# 模板标记验证
php scripts/verify-template-assertions.php --strict
```
