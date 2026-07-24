<?php
/**
 * check-use-statements.php — 验证所有 use 语句引用的类是否存在
 *
 * 原理: 扫描 PHP 文件 → 提取 use Foo\Bar\Baz → 检查 autoloader 能否加载
 * 必须 run 在 composer dump-autoload 之后
 *
 * 用法: php scripts/check-use-statements.php
 * 退出: 0=全部存在, 1=有断链
 */
$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$broken = [];
$checked = 0;

$dirs = ['public', 'app', 'modules', 'tools', 'bin'];

foreach ($dirs as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) continue;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') continue;

        $content = file_get_contents($file->getRealPath());
        $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));

        // 提取所有 use 语句: use Foo\Bar\Baz; 或 use Foo\Bar\{A, B};
        preg_match_all('/^use\s+([\w\\\\]+(?:\s*\{[^}]+\})?)\s*;/m', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $useStmt = trim($m[1]);

            // 跳过 PHP 内置类、Composer 包
            $firstPart = explode('\\', $useStmt)[0];
            if (!in_array($firstPart, ['Converge', 'Tools', 'App'])) continue;

            // 检查类是否存在
            $fqcn = str_replace(' ', '', $useStmt);
            $checked++;
            if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn)) continue;

            $broken[] = [$rel, $useStmt];
        }
    }
}

if (empty($broken)) {
    echo "✅ All {$checked} use statements valid\n";
    exit(0);
}

echo "❌ " . count($broken) . " broken use statements:\n\n";
foreach ($broken as $b) {
    echo "  {$b[0]}\n    → use {$b[1]}; (not found)\n\n";
}
exit(1);
