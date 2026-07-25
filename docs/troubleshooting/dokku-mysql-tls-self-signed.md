# MySQL 8.0 自签名证书导致 PHP mysqli 连接失败

> Status: 已验证 | Tags: dokku, mysql, tls, php

## Detection

```
ERROR 2026 (HY000): TLS/SSL error: self-signed certificate in certificate chain + entrypoint 'MySQL timeout'
```

## Root Cause

MySQL 8.0 默认启用 TLS，Dokku MySQL 插件生成自签名证书。PHP mysqli 构造函数默认验证证书 → 验证失败 → 连接被拒

## Fix

入口脚本用 mysqladmin ping (支持 --ssl-mode) 替代 PHP mysqli 做健康检查:
  mysqladmin ping -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" --silent

应用层 PHP PDO:
  new PDO('mysql:...', $user, $pass, [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false])

## Verify

```bash
docker exec <container> mysqladmin ping -h <host> -u <user> -p<pass> --silent
```

### Notes

Docker 内网通信不需要 TLS 证书验证。mysqladmin ping (支持 --ssl-mode) 比 PHP mysqli 更适合做容器健康检查
