---
name: -sql-alias-mismatch--getconversion别名c-camp不一致
description: JOIN campaigns c但SELECT用camp.*,空壳时未跑到潜伏,CAPI真实现暴露
metadata:
  type: feedback
---

# [sql|alias-mismatch] getConversion别名c/camp不一致

**检测模式**: sql|alias-mismatch|Unknown column.*camp

**根因**: SQL别名JOIN用c但SELECT用camp,fireFacebookCAPI空壳时该分支没跑到,bug潜伏

**修复**: 统一别名c→camp;空壳/未跑到路径潜伏bug必须真实数据流端到端才暴露

**验证**: 退款端到端DB落库成功