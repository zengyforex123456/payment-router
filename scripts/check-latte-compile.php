<?php
declare(strict_types=1);

/**
 * Latte Compile Check — 验证所有 .latte 模板语法正确
 * Usage: php scripts/check-latte-compile.php [template.latte]
 * Exit: 0 = all pass, 1 = compile errors
 */

require_once __DIR__ . '/../vendor/autoload.php';

$engine = new Latte\Engine();
$engine->setTempDirectory(sys_get_temp_dir() . '/latte-check');
$target = $argv[1] ?? null;
$errors = [];

if ($target) {
    $files = [realpath($target) ?: $target];
} else {
    $files = array_merge(
        glob(__DIR__ . '/../templates/*.latte') ?: [],
        glob(__DIR__ . '/../templates/**/*.latte') ?: [],
        glob(__DIR__ . '/../templates/**/**/*.latte') ?: []
    );
}

$total = count($files);
foreach ($files as $file) {
    $rel = str_replace(str_replace('\\', '/', realpath(__DIR__ . '/..')), '', str_replace('\\', '/', realpath($file) ?: $file));
    try {
        $engine->compile($file);
    } catch (\Throwable $e) {
        echo "❌ $rel: " . $e->getMessage() . "\n";
        $errors[] = $rel;
    }
}

$passed = $total - count($errors);
echo "═══ Latte Compile ═══\n";
echo "  $passed/$total pass\n";
if (empty($errors)) { echo "  ✅ All ok\n"; exit(0); }
echo "  ❌ " . count($errors) . " failures\n";
exit(1);
