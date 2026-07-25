# Dokku MySQL 容器 root 密码不可直接使用

> Status: 已验证 | Tags: dokku, mysql, password, access

## Detection

```
ERROR 1045 (28000): Access denied for user 'root'@'localhost'
```

## Root Cause

Dokku MySQL 插件的 root 密码存储在容器环境变量 MYSQL_ROOT_PASSWORD 中，SSH 远程执行时 $MYSQL_ROOT_PASSWORD 不会展开（变量在远程 shell 而非容器内）

## Fix

1. 用应用容器的 PHP 连接（已有 DB 凭据）
2. 或用 mysql 用户（Dokku link 创建的）
3. 或用 docker exec <container> printenv MYSQL_PASSWORD 获取密码

## Verify

```bash
docker exec dokku.mysql.converge-db mysqladmin ping -u mysql --silent
```

### Notes

直接操作数据库优先用应用容器的 PHP 而不是 mysql CLI root 账号
