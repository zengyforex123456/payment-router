---
name: docker-deploy-error-patterns
description: Docker 部署 7 种错误指纹 + 修复模式 — Converge 实战验证
metadata: 
  node_type: memory
  type: feedback
  originSessionId: c898435f-e7cd-482c-9fbb-6adbb847449c
---

# Docker 部署常见错误 — 7 种指纹 + 自动修复

> 来源: Converge Docker 全链路部署实战 (2026-07-14~15)
> 耗时: 11 次部署 → 全绿, 从 44 min 降到 3 min

## 错误指纹表

| # | 检测模式 | 根因 | 修复 | 自动? |
|---|---------|------|------|:---:|
| 1 | `TLS/SSL error: self-signed certificate` | MySQL 8.0 客户端默认验证 TLS 证书, Docker 内网自签名被拒 | `--ssl-verify-server-cert=0` (仅内网) | ✅ |
| 2 | `Table 'xxx.yyy' doesn't exist` + Docker 新部署 | depends_on 只验证 MySQL 进程, 不验证 schema | migrator 容器 + `depends_on service_completed_successfully` | ✅ |
| 3 | 迁移 `NNN_xxx.sql` 依赖表在后续迁移才创建 | 编号冲突 (如 003 引用 007 的表) | 重命名文件到正确顺序 (如 003→080) | ❌ |
| 4 | Docker build `"/php-prod.ini": not found` | 文件未 git add (本地有, 服务器无) | 每次新增 Docker 引用文件后 `git add` + 验证 | ❌ |
| 5 | 新容器端口绑定失败, 旧容器占端口 | 旧 docker-compose 残留容器未清理 | deploy 脚本 `docker stop/rm` 旧容器后再 up | ✅ |
| 6 | 页面输出 `{__(...)}` 原样显示 | PHP heredoc 不支持函数调用插值 | 预计算翻译字符串为变量, 用 `{$var}` 插值 | ❌ |
| 7 | 修复代码部署后不生效 | OPcache `validate_timestamps=0` 缓存旧代码 | `docker compose up --build` 重建镜像 (新容器=新 OPcache) | ✅ |

## 预防: 本地先测再 push

```bash
# 每次提交前跑 (3 min)
bash scripts/local-test.sh   # build → health → 36项ui-test → 语法
# 全绿 → git push
```

## 关联
[[docker-schema-not-initialized]] [[docker-production-data-safety]] [[docker-local-test-before-deploy]]
