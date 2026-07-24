---
name: converge-p0-p2-security-patches
description: Converge P0+P1+P2 上线前安全/运维缺口全部修复 — HTTPS·限流·CSRF·租户隔离·会话·监控·CORS·错误页面·DB重连
metadata: 
  node_type: memory
  type: project
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# Converge 上线前全量缺口修复 (2026-07-13)

## P0 (阻塞)
- **HTTPS 强制**: `config.php` → `HTTPS_ENFORCED` (开发OFF, 生产ON), bootstrap 301重定向, nginx SSL参考配置

## P1 (上线前必修)
- **CSRF**: `api-fire-postback.php` + `api-refund-conversion.php` + `api-funnel.php` — `Csrf::validate()` POST校验
- **租户隔离**: `TenantManager::getAll()/getById()` → TenantContext访问控制, 跨租户拒绝
- **会话可扩展**: `SESSION_HANDLER=database` → `DbSessionHandler` · GC probability配置
- **监控告警**: `scripts/monitor-health.php` — 磁盘/MySQL/EventStore/回传失败率/死信/错误日志 6项
- **API限流**: API v1已接线(ApiKeyAuth), 无需改动

## P2 (上线首周)
- **错误页面**: `public/errors/404.php` + `500.php`
- **上传校验**: `api-funnel.php` — `mime_content_type()` MIME检测
- **CORS收敛**: `CORS_ORIGIN` config → 可配置域名(非wildcard)
- **健康检查**: `health.php` — DB_NAME常量(原硬编码'converge')
- **DB重连**: `bootstrap.php` — CONNECT_TIMEOUT 5s + READ_TIMEOUT 30s + ping检测

## 开关架构

所有安全功能遵循统一模式:
```
APP_ENV=development → 全部OFF (不影响开发)
APP_ENV=production  → 全部ON
单独开关= false     → 强制关某单项
```

## 相关记忆
- [[converge-session-summary]] — 会话背景
- [[email-service-capability]] — 邮件模块
- [[converge-bootstrap-wiring-51]] — bootstrap模式
