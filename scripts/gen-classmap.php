<?php
/**
 * gen-classmap.php — 扫描 modules/ → 自动更新 composer.json classmap
 *
 * 用法: php scripts/gen-classmap.php [--write]
 * 自动注册: 加入 .githooks/pre-commit → bin/tool run gen-classmap
 */
$root = dirname(__DIR__);
$modulesDir = $root . '/modules';

if (!is_dir($modulesDir)) {
    echo "ERROR: modules/ not found\n";
    exit(1);
}

$classmap = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($it as $file) {
    if ($file->getExtension() !== 'php') continue;
    $real = $file->getRealPath();
    $rel = str_replace('\\', '/', substr($real, strlen($root) + 1));

    $content = file_get_contents($real);
    $tokens = token_get_all($content);
    $namespace = '';
    $className = '';
    $len = count($tokens);

    for ($i = 0; $i < $len; $i++) {
        if (!is_array($tokens[$i])) continue;
        if ($tokens[$i][0] === T_NAMESPACE) {
            $namespace = '';
            for ($j = $i + 1; $j < $len; $j++) {
                if ($tokens[$j] === ';' || $tokens[$j] === '{') break;
                if (is_array($tokens[$j])) $namespace .= $tokens[$j][1];
            }
            $namespace = trim($namespace);
        }
        if ($tokens[$i][0] === T_CLASS || $tokens[$i][0] === T_INTERFACE || $tokens[$i][0] === T_TRAIT) {
            for ($j = $i + 1; $j < $len; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $className = $tokens[$j][1];
                    break;
                }
            }
        }
    }

    if ($namespace !== '' && $className !== '') {
        $classmap[$namespace . '\\' . $className] = $rel;
    }
}

ksort($classmap);
$paths = array_values(array_unique($classmap));
sort($paths);

$write = in_array('--write', $argv);
if ($write) {
    $composerFile = $root . '/composer.json';
    $composer = json_decode(file_get_contents($composerFile), true);
    $composer['autoload']['classmap'] = $paths;
    file_put_contents(
        $composerFile,
        json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    echo count($classmap) . " classes → " . count($paths) . " paths\n";
    echo "OK — Run: composer dump-autoload\n";
} else {
    echo count($classmap) . " classes. Use --write to update composer.json\n";
    foreach (array_slice($classmap, 0, 3) as $cls => $path) {
        echo "  {$cls} → {$path}\n";
    }
}
