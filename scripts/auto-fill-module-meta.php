<?php
/**
 * auto-fill-module-meta.php — 批量补全所有模块元数据 (P1-P3)
 *
 * 自动检测: depends_on · config/ · tests/ · Contract/
 * Usage: php scripts/auto-fill-module-meta.php [--dry]
 */
$root = dirname(__DIR__);
$dry = in_array('--dry', $argv);
$modulesDir = $root . '/modules';
$moduleNames = array_map('basename', glob($modulesDir . '/*', GLOB_ONLYDIR) ?: []);
sort($moduleNames);
$stats = ['depends_on' => 0, 'config' => 0, 'tests' => 0, 'contract' => 0];

echo "\n\033[1;36m═══ 补全模块元数据" . ($dry ? " (--dry)" : "") . " ═══\033[0m\n\n";

foreach ($moduleNames as $name) {
    $dir = "$modulesDir/$name";
    echo "\033[33m" . str_pad($name, 20) . "\033[0m ";

    // 1. depends_on
    $deps = detectDependencies($dir, $moduleNames);
    if (!empty($deps)) {
        updateModuleJson("$dir/module.json", $deps, $dry);
        echo "deps+" . count($deps) . " ";
        $stats['depends_on'] += count($deps);
    }

    // 2. config
    if (!file_exists("$dir/config/module.php")) {
        if (!$dry) { @mkdir("$dir/config", 0755, true); file_put_contents("$dir/config/module.php", configTemplate($name)); }
        $stats['config']++; echo "cfg+ ";
    }

    // 3. tests
    if (!is_dir("$dir/tests")) {
        if (!$dry) { @mkdir("$dir/tests/Unit", 0755, true); @mkdir("$dir/tests/Integration", 0755, true); }
        $stats['tests']++; echo "tst+ ";
    }

    // 4. contract
    if (!is_dir("$dir/Contract") && hasPublicApi($dir)) {
        if (!$dry) { @mkdir("$dir/Contract", 0755, true); file_put_contents("$dir/Contract/{$name}Contract.php", contractSkeleton($name)); }
        $stats['contract']++; echo "ctr+";
    }

    echo "\n";
}

echo "\n" . str_repeat('─', 56) . "\n";
printf("  deps: %d | config: %d | tests: %d | contract: %d\n", $stats['depends_on'], $stats['config'], $stats['tests'], $stats['contract']);
if ($dry) echo "  \033[90m--dry: no files written\033[0m\n";
echo str_repeat('─', 56) . "\n\n";

// ═══ Functions ═══

function detectDependencies(string $dir, array $allModules): array {
    $deps = [];
    foreach (glob($dir . '/**/*.php') ?: [] as $file) {
        if (preg_match_all('/use\s+Converge\\\\Modules\\\\([A-Za-z]+)\\\\/', file_get_contents($file), $m)) {
            foreach ($m[1] as $mod) {
                if ($mod !== basename($dir) && in_array($mod, $allModules)) $deps[$mod] = true;
            }
        }
    }
    return array_keys($deps);
}

function updateModuleJson(string $path, array $deps, bool $dry): void {
    if ($dry) return;
    $json = file_exists($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    $json['depends_on'] = array_values(array_unique(array_merge($json['depends_on'] ?? [], $deps)));
    file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function configTemplate(string $name): string {
    $key = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name));
    return "<?php\ndeclare(strict_types=1);\n/** {$name} config — php bin/platform config:sync */\nreturn ['{$key}' => []];\n";
}

function contractSkeleton(string $name): string {
    return "<?php\ndeclare(strict_types=1);\nnamespace Converge\\Modules\\{$name}\\Contract;\n"
        . "use Converge\\Core\\Contract\\ModuleContract;\n"
        . "interface {$name}Contract extends ModuleContract\n{\n    // TODO: define public API\n}\n";
}

function hasPublicApi(string $dir): bool {
    foreach (['Application', 'Controller'] as $sub) {
        if (!empty(glob($dir . '/' . $sub . '/*.php') ?: [])) return true;
    }
    return false;
}
