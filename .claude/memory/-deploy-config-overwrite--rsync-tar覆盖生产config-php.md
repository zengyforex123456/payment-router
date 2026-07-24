---
name: -deploy-config-overwrite--rsync-tar覆盖生产config-php
description: 部署打包未排除config,空密码覆盖生产真实凭证致Access denied
metadata:
  type: feedback
---

# [deploy|config-overwrite] rsync/tar覆盖生产config.php

**检测模式**: deploy|config-overwrite|Access denied for user.*localhost

**根因**: tar打包未排除config/config.php,本地占位版覆盖生产凭证

**修复**: 从备份tar提取原config覆盖回(不-O打印);部署打包必须排除config/.env

**验证**: php -r连接DB成功