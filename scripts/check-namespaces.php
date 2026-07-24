<?php
/**
 * check-namespaces.php — 检测项目中已知的错误命名空间引用
 *
 * 已知错误模式 (迁移后命名空间未更新):
 *   Converge\UI\LatteEngine        → Converge\UI\Engine\LatteEngine
 *   Converge\Auth\Auth             → Converge\Security\Auth
 *   Converge\UI\Templates\*        → Converge\UI\Legacy\Templates\*
 *
 * 用法:
 *   php scripts/check-namespaces.php          # 检查所有 PHP 文件
 *   php scripts/check-namespaces.php --fix    # 自动修复
 */

$root = dirname(__DIR__);
$fix = in_array('--fix', $argv);

$patterns = [
    ['wrong' => '\\Converge\\UI\\LatteEngine', 'right' => '\\Converge\\UI\\Engine\\LatteEngine', 'desc' => 'LatteEngine 命名空间 (\)'],
    ['wrong' => 'use Converge\\UI\\LatteEngine;', 'right' => 'use Converge\\UI\\Engine\\LatteEngine;', 'desc' => 'LatteEngine 命名空间 (use)'],
    ['wrong' => '\\Converge\\Auth\\Auth', 'right' => '\\Converge\\Security\\Auth', 'desc' => 'Auth 命名空间 (\)'],
    ['wrong' => 'use Converge\\Auth\\Auth;', 'right' => 'use Converge\\Security\\Auth;', 'desc' => 'Auth 命名空间 (use)'],
];

$violations = 0;
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
        $changed = false;

        foreach ($patterns as $p) {
            if (str_contains($content, $p['wrong'])) {
                $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));
                if ($fix) {
                    $content = str_replace($p['wrong'], $p['right'], $content);
                    $changed = true;
                    echo "  FIXED: {$rel} — {$p['desc']}\n";
                } else {
                    echo "  {$rel}: {$p['wrong']} → {$p['right']}\n";
                }
                $violations++;
            }
        }

        if ($changed) {
            file_put_contents($file->getRealPath(), $content);
        }
    }
}

if ($violations === 0) {
    echo "✅ 命名空间全部正确\n";
    exit(0);
}

echo "\n❌ {$violations} 处命名空间错误\n";
exit($fix ? 0 : 1);
