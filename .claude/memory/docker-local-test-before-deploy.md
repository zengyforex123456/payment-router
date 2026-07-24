---
name: docker-local-test-before-deploy
description: Docker 部署铁律 — 本地全绿再 push，服务器调试 11 次→1 次的教训
metadata: 
  node_type: memory
  type: feedback
  originSessionId: c898435f-e7cd-482c-9fbb-6adbb847449c
---

# Docker 部署铁律：本地全绿再 push

**检测模式**: 服务器部署反复失败 >3 次 → 检查是否跳过了本地测试

**根因**: 直接 push → 等服务器 Docker build (4-8 min) → 测试 → 失败 → 修 → 再 push。每次修复 <1 min，等待 >4 min，浪费 80% 时间。

**教训数据**: Converge Docker 部署调试中 11 次 commit，7 个问题可本地发现：
- TLS 自签名证书 (mysqladmin --ssl-verify-server-cert=0)
- 文件未 git add (php-prod.ini, php-fpm-prod.conf)
- 迁移编号冲突 (003 依赖 007)
- 端口冲突 (旧 nginx 占 80)
- OPcache 缓存旧代码 (validate_timestamps=0)
- PHP heredoc 函数调用 (i18n 不翻译)
- 裸域 DNS 缺失 A 记录

**正确流程**:
```
1. docker compose -f docker-compose.server.yml up -d --build (本地, 5 min)
2. curl localhost/ui-test.php  (36 项检查)
3. curl localhost/health       (4 支柱)
4. 全绿 → git push → 服务器自动部署 (4 min)
```

**脚本**: `scripts/local-test.sh` — 一键构建+启动+测试+语法检查

**关联**: [[docker-schema-not-initialized]] [[docker-production-data-safety]]
