---
name: -db-migration-断链--migrationrunner生产旧库从头撞车
description: 旧版手动部署无migration记录,runner从001重跑撞Duplicate column停在012
metadata:
  type: feedback
---

# [db|migration-断链] MigrationRunner生产旧库从头撞车

**检测模式**: db|migration-断链|Duplicate column.*ALTER TABLE

**根因**: 生产库旧schema已存在但无migration应用记录,runner从头跑撞已存在列

**修复**: 只单独跑新增migration(076/077),用multi_query执行整文件(手动分号拆分会拆坏CREATE)

**验证**: SHOW TABLES确认新表已建