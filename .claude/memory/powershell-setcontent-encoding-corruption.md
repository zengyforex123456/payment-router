---
name: powershell-setcontent-encoding-corruption
description: PowerShell Set-Content 破坏 UTF-8 PHP 文件 — 用 PHP file_get/put_contents 批量编辑替代
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 88edd302-119f-44ea-bb1f-3266751e0c7d
  modified: 2026-07-19T06:27:48.062Z
---

# PowerShell Set-Content 破坏 PHP 编码

**检测模式**: `php -l` 报 `Parse error: unexpected identifier` 在 emoji/中文位置，但肉眼查看文件内容正常

**根因**: PowerShell 5.1 的 `Set-Content` 默认使用系统 ANSI 编码 (GBK/CP1252)，而非 UTF-8。使用 `-NoNewline` 时进一步加剧编码损坏，导致 UTF-8 多字节字符（emoji、中文）被切割为非法字节序列。

**典型错误**:
```
PHP Parse error: syntax error, unexpected identifier "🚫" in tools/DeployTool.php on line 171
```

**修复**:
```php
// 用 PHP 的 file_get_contents + file_put_contents 做批量编辑（保留编码）
php -r "
foreach (glob('tools/*.php') as \$f) {
    \$c = file_get_contents(\$f);
    \$c = str_replace('old', 'new', \$c);
    file_put_contents(\$f, \$c);
}
"
```

**恢复**:
```bash
# 已跟踪文件: git checkout 恢复
git checkout -- tools/AuditProject.php

# 新文件: 重新 Write
```

**预防（门禁）**:
- `php -l` 检查所有 PHP 文件 → 编码损坏会立即触发 parse error
- 禁止用 PowerShell 做文件内容编辑 → 用 Write/Edit 工具或 PHP 脚本
- 批量文本替换优先用 PHP 脚本，次选 Bash sed，禁选 PowerShell Set-Content

**验证**: `find . -name '*.php' -not -path './vendor/*' | xargs -n1 php -l | grep 'Parse error'` → 期望 0
