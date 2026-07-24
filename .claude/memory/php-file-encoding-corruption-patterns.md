---
name: php-file-encoding-corruption-patterns
description: Common encoding corruption patterns in PHP view files and how to fix them
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# PHP 文件编码损坏 — 检测与修复

**检测模式**: 
- `php -l` 报 `syntax error, unexpected identifier "?"` 或 `unexpected single-quoted string`
- 文件中出现 `?>` 不在 PHP 标签位置、emoji 显示为 `?`
- `cat -A file.php` 显示非 ASCII 乱码

**常见损坏模式与修复**:

| 损坏 | 原因 | 修复 |
|------|------|------|
| `echo '??` | em-dash `—` 被编码损坏 | `echo '—'` |
| `'? : '?>` | emoji `✅`/`❌` 被截断 | `'(exists)' : '(missing)'` |
| `'?>` (单引号+问号+关闭标签) | 同上，PHP 解析器读成字符串未闭合 | 替换为 `'—' ?>` |
| `<span>??/span>` | `>` 字符被吞 | `<span>▼</span>` |

**批量修复脚本**:
```php
$fixes = [
    "'?>" => "'); ?>",
    "'? :" => "'(ok)' :",
    ": '? " => ": '(n/a)' ",
    "echo '?;" => "echo '—';",
];
foreach ($fixes as $search => $replace) {
    $f = str_replace($search, $replace, $f);
}
```

**预防**: 
- NEVER use `Set-Content` in PowerShell for PHP files — defaults to UTF-16 LE
- Use `Write` tool or `file_put_contents()` in PHP
- Git 提交前跑 `php -l` 检查所有修改的 .php 文件

**关联**: [[converge-dev-patterns]] [[php-comment-star-slash-termination]]
