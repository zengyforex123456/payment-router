---
name: script-proliferation-root-cause
description: "零散脚本泛滥的根因分析与根治方案 — 22个部署脚本→1个#[Tool]命令"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 88edd302-119f-44ea-bb1f-3266751e0c7d
  modified: 2026-07-19T06:07:31.068Z
---

# 脚本泛滥根因：不可发现 + 无门禁 + 无参数化

**检测模式**: 同一目录出现 3+ 个功能相似但参数不同的 `.sh` 脚本

**根因**: 脚本不是可发现的能力（没有 `bin/tool list` 注册表），开发者不知道已有脚本存在 → 复制粘贴 → 新脚本

**五层因果链**:
```
症状: 22个脚本、2个目录、重复逻辑
  ↑ 直接: 每个新需求→新建脚本，从旧脚本复制粘贴
  ↑ 深层: 没有统一入口，写脚本的人不知道已有脚本存在
  ↑ 根因: 脚本不是可发现的能力——没有注册表
  ↑ 元根因: 架构上允许"随手写脚本"，没有强制门禁
```

**三类重复**:
- 跨目录复制: `data/scripts/` 和 `scripts/` 两套目录，同一功能各写一份
- 环境硬编码: dev/staging/prod 各写一个，区别只在一行 compose 路径
- 方法硬编码: docker/rsync/scp 各写一个，区别只在传输方式

**根治方案**:
1. `#[Tool]` 注册 → `bin/tool list` 可发现所有工具
2. 参数化 → 一个类处理所有 env/action/method 组合
3. 单入口 → `bin/platform deploy` 唯一命令
4. 目录门禁 → `enforce-directory.php` 阻止在 scripts/ 新建功能脚本
5. 废弃标记 → 旧目录加 DEPRECATED 文件，指向新命令

**关键原则**: 能力的可发现性 = 重复的抗体。`bin/tool list` 让开发者看到已有工具，消除"我不知道有这个"导致的重复。

**验证**: `bin/platform deploy --help` 展示所有功能，不再需要多个脚本
