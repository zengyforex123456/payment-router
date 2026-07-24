---
name: namespace-migration-automation
description: 批量命名空间迁移 — 用 PHP 脚本替换 sed，77 文件零失误
metadata: 
  node_type: memory
  type: feedback
  originSessionId: c898435f-e7cd-482c-9fbb-6adbb847449c
---

# 批量命名空间迁移模式

**检测模式**: 重构时需更新 50+ 文件的 `use` 语句和 `namespace` 声明

**根因**: Git Bash 的 sed 对反斜杠转义不一致（`\\\\` vs `\\`），导致正则失败

**修复**: 用 PHP 脚本替代 sed 做批量替换

## 迁移脚本模板

```php
// migrate-namespaces.php
$map = [
    'use Converge\\Auth\\Auth' => 'use Converge\\Security\\Auth',
    'use Converge\\Core\\Hooks' => 'use Converge\\Core\\Hook\\Hooks',
    // ... 30+ 映射
];

$dirs = [$base . '/src', $base . '/views', $base . '/public', $base . '/tests'];
foreach ($dirs as $dir) {
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') continue;
        $content = file_get_contents($file->getPathname());
        $original = $content;
        $content = str_replace(array_keys($map), array_values($map), $content);
        if ($content !== $original) {
            file_put_contents($file->getPathname(), $content);
        }
    }
}
```

## 为什么不用 sed

Git Bash 中 sed 对 PHP 的反斜杠命名空间有问题:
- `sed 's/namespace Converge\\Core/namespace Converge\\Core\\Hook/'` → 实际输出 `ConvergeCoreHook` (转义被吃)
- 4 个反斜杠才能产生 1 个: `sed 's/...Converge\\\\Core/.../'` → 但不同 shell 行为不一致

结论: PHP 脚本做文本替换最可靠。

## 迁移后的验证

1. `composer dump-autoload --optimize` → 0 PSR-4 警告
2. `grep -rn "old_namespace" src/ views/ tests/` → 0 结果
3. `php vendor/bin/phpunit` → 全绿
4. 安全删除旧文件

## 关键教训

- `class_exists` 不处理 interface，用 `interface_exists` 单独检查
- 先扫描 `src/`, `views/`, `public/`, 再补 `tests/` (容易被遗漏)
- 迁移脚本是一次性的，用完后删除
