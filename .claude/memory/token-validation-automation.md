---
name: token-validation-automation
description: "validate-tokens.php catches self-references, missing refs, low contrast, non-4px spacing"
metadata: 
  node_type: memory
  type: project
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# 令牌自检门禁：从人工到自动化

## 发现的实际问题
通过 `validate-tokens.php` (P0 门禁) 首次运行发现：
1. `--content-tertiary` dark mode: #5a6a82 on #0b1121 = 3.42:1 → 修复为 #7a8a9e (5.43:1)
2. 29 个旧版兼容令牌缺少 dark mode 值（不阻塞）

## 五道检查
| 检查 | 通过 | 发现 |
|------|:---:|------|
| 自引用 | ✅ | — (已在 848a8b1 修复) |
| 缺失引用 | ✅ | — |
| 色对对比度 | ✅ | 1 处修复 |
| 间距4px倍数 | ✅ | — |
| 命名一致性 | ⚠️ | 29 旧兼容令牌 |

## 常规运行
```bash
php scripts/validate-tokens.php          # 终端报告
php scripts/validate-tokens.php --json   # CI JSON
```
