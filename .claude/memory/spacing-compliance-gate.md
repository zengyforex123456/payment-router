---
name: spacing-compliance-gate
description: check-spacing-compliance.php scans for non-8px-multiple spacing values
metadata: 
  node_type: memory
  type: project
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# 间距合规门禁：8px 网格强制

## 检测内容
`check-spacing-compliance.php` 扫描：
1. CSS: `padding/margin/gap/width/height` 中非 8px 倍数的 px 值
2. PHP/Latte: Tailwind 非标准 spacing 类 (p-7, gap-5, m-3.5, w-20...)

## 首次全量结果
- 442 文件扫描
- 1017 处历史违规（不阻塞，仅告警）
- 0 处新增违规

## 运行
```bash
php scripts/check-spacing-compliance.php          # 全量报告
php scripts/check-spacing-compliance.php --staged # 仅 staged (阻塞新增)
php scripts/check-spacing-compliance.php --json   # CI JSON
```
