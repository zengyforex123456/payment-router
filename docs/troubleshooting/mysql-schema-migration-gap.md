# 迁移文件存在但未真正执行 — 表缺列

> Status: 已验证 | Tags: mysql, migration, schema, dokku

## Detection

```
INSERT 报 Unknown column + migration 文件存在但数据库中列缺失
```

## Root Cause

MigrationRunner 用 migrations 表追踪，但 MySQL 8.0.28 及更早版本不支持 ALTER TABLE ADD COLUMN IF NOT EXISTS → SQL 语法错误被 catch → 跳过但可能已被记录

## Fix

1. 用 information_schema.COLUMNS 检查列是否存在（先查后加）
2. 升级 MySQL 到 8.0.29+
3. 或迁移 SQL 改为 check-and-add 模式

## Verify

```bash
docker exec <container> mysql -e 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME="users"'
```

### Notes

迁移追踪系统和实际 schema 可能不一致。部署后验证关键列存在
