<?php
/**
 * check-requires.php — 检测所有 require/include 引用的文件是否存在
 *
 * 扫描: public/*.php, app/*.php, modules/*.php, templates/**\/*.latte
 * 提取: require_once|require|include_once|include + {include_php}
 * 验证: 每个引用路径指向的文件是否存在
 *
 * 用法: php scripts/check-requires.php
 * 退出: 0=全部存在, 1=有缺失
 */

$root = dirname(__DIR__);
$missing = [];
$checked = 0;

// 豁免: 运行时动态文件、可选依赖、变量引用
function isExempt(string $ref, string $fullPath): bool {
    // Variable references (not real paths)
    if (preg_match('/[\{\$}]/', $ref)) return true;
    if (preg_match('/[\{\$}]/', $fullPath)) return true;

    // Glob patterns
    if (str_contains($ref, '*')) return true;

    // Runtime data (created at runtime, optional)
    $runtimePrefixes = ['/storage/', '/data/', '/cache/', '/logs/', '/tmp/', '/contract/'];
    foreach ($runtimePrefixes as $p) {
        if (str_starts_with($fullPath, $p) && !str_ends_with($fullPath, '.php')) return true;
    }

    // Optional/data files (created at runtime)
    $optional = ['version.php', 'GeoLite2', 'GeoIP', '.jsonl', '.mmdb', '/xxx', '/path/to/file',
                 '.json', '.sh', 'installer/locked', '@playwright', 'bootstrap_web_paths.php',
                 'module-scaffold.sh', 'test.php', '/data/', '/contract/', 'snapshot'];
    foreach ($optional as $p) {
        if (str_contains($fullPath, $p)) return true;
    }

    // Fabric directories (virtual modules, not PHP files)
    $fabricDirs = ['/Observability', '/Traceability', '/Resilience', '/Security',
                   '/Performance', '/Growth', '/Evolution', '/Core', '/Tracking',
                   '/Stats', '/Entity', '/Api', '/SaaS', '/Facebook', '/Auth', '/views'];
    foreach ($fabricDirs as $p) {
        if ($fullPath === $root . $p) return true;
    }

    // Known-moved modules (classmap handles these at runtime)
    $moved = ['app/Tracking/PostbackDispatcher', 'app/CopyEvaluator/', 'Knowledge/KagClient',
              'app/Auth/Auth.php', 'app/Auth/Permission.php', 'app/Facebook/FacebookCost'];
    foreach ($moved as $p) {
        if (str_contains($fullPath, $p)) return true;
    }

    // {include_php} paths — resolved at runtime by LatteEngine, not static
    if (str_contains($ref, 'include_php')) return true;

    return false;
}

// ═══ 1. PHP 文件 ───
foreach (['public', 'app', 'modules', 'tools', 'bin', 'scripts'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) continue;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') continue;

        $content = file_get_contents($file->getRealPath());
        $relFile = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));

        // Match: require(_once)? ['"](path)['"]
        preg_match_all(
            "/(?:require|include)(?:_once)?\s*\(\s*(?:__DIR__\s*\.\s*)?['\"]([^'\"]+)['\"]/",
            $content, $matches, PREG_SET_ORDER
        );

        // Match: require(_once)? APP_ROOT . '/path'
        preg_match_all(
            "/APP_ROOT\s*\.\s*['\"]([^'\"]+)['\"]/",
            $content, $appRootMatches, PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $refPath = $m[1];
            if (str_starts_with($refPath, '/')) {
                $fullPath = $refPath; // absolute
            } else {
                // Relative to the file's directory
                $fullPath = dirname($file->getRealPath()) . '/' . $refPath;
            }
            $fullPath = realpath($fullPath) ?: $fullPath;
            $fullPath = str_replace('\\', '/', $fullPath);
            if (!file_exists($fullPath) && !isExempt($m[0], $fullPath)) {
                $missing[] = [$relFile, $m[0], $fullPath];
            }
            $checked++;
        }

        foreach ($appRootMatches as $m) {
            $fullPath = $root . '/' . ltrim($m[1], '/');
            if (!file_exists($fullPath) && !isExempt('APP_ROOT', $fullPath)) {
                $missing[] = [$relFile, 'APP_ROOT . \'' . $m[1] . '\'', $fullPath];
            }
            $checked++;
        }
    }
}

// ═══ 2. Latte 模板 — {include_php} ───
$latteDir = $root . '/templates';
if (is_dir($latteDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($latteDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->getExtension() !== 'latte') continue;

        $content = file_get_contents($file->getRealPath());
        $relFile = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));

        preg_match_all(
            "/\{include_php\s*\(\s*['\"]([^'\"]+)['\"]/",
            $content, $matches, PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            // {include_php} paths are relative to templates/ directory
            $fullPath = dirname($file->getRealPath()) . '/' . $m[1];
            $fullPath = realpath($fullPath) ?: $fullPath;
            $fullPath = str_replace('\\', '/', $fullPath);
            if (!file_exists($fullPath)) {
                $missing[] = [$relFile, '{include_php \'' . $m[1] . '\'', $fullPath];
            }
            $checked++;
        }
    }
}

// ═══ Output ───
if (empty($missing)) {
    echo "✅ All {$checked} references OK\n";
    exit(0);
}

echo "❌ " . count($missing) . " broken references found:\n\n";
foreach ($missing as $m) {
    echo "  📄 {$m[0]}\n";
    echo "     Ref: {$m[1]}\n";
    echo "     Missing: {$m[2]}\n\n";
}
exit(1);
