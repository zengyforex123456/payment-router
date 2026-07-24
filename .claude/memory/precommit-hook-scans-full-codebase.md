---
name: precommit-hook-scans-full-codebase
description: Pre-commit hook scans entire repo not just staged files — historical debt blocks unrelated changes
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# Pre-commit 门禁扫全量非增量：历史债阻塞无关改动

**检测模式**: 只改了 2-3 个文件但 pre-commit 报 200+ WCAG 违规·5 个一致性错误

**根因**: `check-contrast.php` 和 `check-consistency.php` 全量扫描整个项目，不区分 staged vs unstaged 文件。历史 CSS 债（app-bundle.css/skeleton.css/themes.css）的违规会阻塞任何提交。

**修复**:
1. 短期：门禁脚本加 `--staged` 模式只扫 git diff 中的文件
2. 应急：确认改动文件无新增违规后，跳过门禁提交
3. 长期：历史债分批修复后，门禁改为增量模式

**验证**: `git diff --cached --name-only` 确认只有目标文件在 staged 区

**相关知识**: [[wcag-contrast-gate]] [[check-consistency-full-scan]]
