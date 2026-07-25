# dokku proxy:disable → proxy:enable 清除域名设置

> Status: 已验证 | Tags: dokku, nginx, domain, vhost

## Detection

```
proxy:disable → proxy:enable → domains:report 显示服务器 hostname 而非自定义域名
```

## Root Cause

dokku proxy:disable 清除 nginx config，proxy:enable 重新生成时使用服务器 hostname 作为默认 VHOST，不恢复自定义域名

## Fix

dokku proxy:disable <app>
dokku proxy:enable <app>
dokku domains:set <app> example.com www.example.com   # ← 必须！
dokku proxy:build-config <app>

## Verify

```bash
grep 'server_name' /home/dokku/<app>/nginx.conf | grep -v 'server_name _' | grep -v localhost
```

### Notes

proxy:disable/enable 的副作用：域名丢失。优先使用 proxy:build-config (不丢域名)
