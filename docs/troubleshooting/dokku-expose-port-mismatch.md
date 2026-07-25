# Dokku EXPOSE 端口不匹配导致网站不可访问

> Status: 已验证 | Tags: dokku, nginx, deploy, port

## Detection

```
EXPOSE 8080 in Dockerfile + nginx listen 8080 + curl returns empty
```

## Root Cause

Dokku 读取 Dockerfile EXPOSE 生成 nginx listen 端口。EXPOSE 8080 导致 nginx 只监听 8080 而非 80，用户浏览器访问 80/443 无法匹配 server block

## Fix

1. 修改 Dockerfile EXPOSE 5000 (Dokku web 标准) 或 EXPOSE 80 (PHP-FPM+Nginx)
2. Procfile 使用 $PORT 变量
3. 紧急修复: dokku proxy:disable <app> → proxy:enable → domains:set

## Verify

```bash
grep listen /home/dokku/<app>/nginx.conf 应显示 listen 80
```

### Notes

Dockerfile EXPOSE 决定 Dokku nginx 监听端口。EXPOSE 8080 → nginx listen 8080 → 用户访问 80/443 无法匹配 → 网站不可访问
