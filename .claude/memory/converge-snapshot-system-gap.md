---
name: converge-snapshot-system-gap
description: 快照系统完全断链——HTML生成器≠JSON Loader，仪表盘显示unknown
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# 快照系统断链 — 从 unknown 到 live 的完整修复

## 症状
仪表盘显示 `快照: unknown`，SnapshotLoader 降级到 DB 实时查询，但版本字段未更新。

## 根因链

```
症状: 快照: unknown
  ← 直接: SnapshotLoader::version() 返回默认值 'unknown'
  ← 深层: loadFromFile() 找不到文件 → loadFromDB() 兜底但未设置 version
  ← 根因1: loadFromDB() 没有 `$this->version = 'live'`
  ← 根因2: generate-static-snapshot.php 只生成 HTML，不生成 JSON
  ← 根因3: 服务器上从未运行过快照生成脚本
```

## 两套系统完全断链

| 系统 | 生成方 | 消费方 | 格式 |
|------|------|------|------|
| 快照查看器 | `generate-static-snapshot.php` | `snapshot-viewer.php` | HTML + index.json |
| 仪表盘数据 | **无** ← 断链 | `SnapshotLoader` → `dashboard.php` | `dashboard-latest.json` |

`SnapshotLoader::loadFromFile()` 期望 `storage/snapshots/{lang}/dashboard-latest.json`，但没有任何脚本生成这个文件。

## 5 处修复

### 1. SnapshotLoader::loadFromDB() — 设置 version
```php
// 修复前: version 保持 'unknown'
// 修复后:
$this->version = 'live';  // 实时数据，非快照
```

### 2. SnapshotLoader::loadFromRedis() — 设置 version
```php
$this->version = 'redis';
```

### 3. generate-static-snapshot.php — 新增 generateDashboardJson()
```php
// 每 5 分钟生成 storage/snapshots/{zh,en}/dashboard-latest.json
// 格式: { version, ts, data: { dashboard, funnel, health } }
```

### 4. dashboard.php — 版本标签本地化
```php
// 修复前: <?=$version?> → 显示 'unknown'
// 修复后:
$verLabel = __('dash.version_' . $version) ?: $version;
// 'live' → '实时', 'file' → '文件快照', 'redis' → '缓存'
```

### 5. lang/{zh,en}.php — 新增版本标签
```php
'dash.version_live' => '实时',    // Live
'dash.version_file' => '文件',    // File
'dash.version_redis' => '缓存',   // Cache
```

## 部署命令（在服务器上运行）
```bash
# 1. 安装文件
cp /tmp/dock-layout.css /var/www/converge/public/assets/css/
cp /tmp/SnapshotLoader.php /var/www/converge/src/Core/
cp /tmp/dashboard.php /var/www/converge/views/
cp /tmp/zh.php /var/www/converge/lang/
cp /tmp/en.php /var/www/converge/lang/
cp /tmp/generate-static-snapshot.php /var/www/converge/scripts/
chown -R www-data:www-data /var/www/converge/

# 2. 生成首轮快照
cd /var/www/converge && php scripts/generate-static-snapshot.php

# 3. 清理 OPcache + 验证
systemctl stop php8.3-fpm && sleep 1 && systemctl start php8.3-fpm
curl -s http://127.0.0.1/health.php

# 4. 设置 cron (每 5 分钟)
echo '*/5 * * * * cd /var/www/converge && php scripts/generate-static-snapshot.php >> storage/logs/snapshots.log 2>&1' | crontab -
```

## 降级链完整状态

| 层级 | 数据源 | 状态 | version |
|:--:|------|:--:|------|
| L1 | 文件快照 `dashboard-latest.json` | 🟢 修复后可用 | `文件快照` |
| L2 | Redis 缓存 | 🔴 未安装 Redis | `缓存` |
| L3 | DB 实时查询 | 🟢 始终可用 | `实时` |
| L4 | 默认空状态 | 🟢 永不为空 | — |

## 关联
- [[dock-layout-css-leakage]] — Dock 布局 CSS 泄漏
- [[sidebar-four-bugs-blind-spot]] — 侧边栏 4 bug 漏检
- [[converge-ui-deploy-lessons]] — 部署经验全集
