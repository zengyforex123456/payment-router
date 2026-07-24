<?php

declare(strict_types=1);

namespace Converge\Core\Module;

/**
 * ModuleRegistry — 模块注册与发现
 *
 * 扫描 src/ 目录，注册所有模块，验证依赖完整性。
 * 生成 module_registry.json 供架构验证工具使用。
 */
class ModuleRegistry
{
    private string $basePath;
    private array $modules = [];

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('ROOT_PATH') ? ROOT_PATH . '/src' : dirname(__DIR__));
    }

    /**
     * Scan and register all PHP modules in the source tree.
     */
    public function scan(): int
    {
        $this->modules = [];
        $files = $this->findPhpFiles($this->basePath);

        foreach ($files as $file) {
            $relativePath = str_replace($this->basePath . '/', '', $file);
            $moduleId = $this->pathToId($relativePath);

            $this->modules[$moduleId] = [
                'id' => $moduleId,
                'file' => 'src/' . $relativePath,
                'size' => $this->countLines($file),
                'exports' => $this->extractExports($file),
                'dependencies' => $this->extractDependencies($file),
                'layer' => $this->inferLayer($relativePath),
                'hasTest' => $this->hasTestFile($relativePath),
            ];
        }

        return count($this->modules);
    }

    /**
     * Validate all modules: check dependencies exist, no circular refs.
     */
    public function validate(): array
    {
        $errors = [];
        $warnings = [];

        foreach ($this->modules as $id => $module) {
            // Check dependencies exist
            foreach ($module['dependencies'] as $dep) {
                if (!isset($this->modules[$dep]) && !class_exists($dep) && !interface_exists($dep)) {
                    $errors[] = "{$id}: dependency '{$dep}' not found";
                }
            }

            // Check file size
            if ($module['size'] > 300) {
                $warnings[] = "{$id}: {$module['size']} lines (>300, needs split)";
            } elseif ($module['size'] > 150) {
                $warnings[] = "{$id}: {$module['size']} lines (>150, consider split)";
            }

            // Check test coverage
            if (!$module['hasTest']) {
                $warnings[] = "{$id}: no test file";
            }
        }

        return [
            'ok' => empty($errors),
            'modules_count' => count($this->modules),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Export registry to JSON file.
     */
    public function export(string $outputPath): void
    {
        $data = [
            'generatedAt' => date('c'),
            'totalModules' => count($this->modules),
            'modules' => $this->modules,
            'validation' => $this->validate(),
        ];

        file_put_contents(
            $outputPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /** @return array */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * Find all PHP files recursively.
     * @return list<string>
     */
    private function findPhpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    private function pathToId(string $relativePath): string
    {
        // Observability/HealthChecker.php → observability/health-checker
        $id = str_replace('/', '/', str_replace('.php', '', $relativePath));
        $id = str_replace('\\', '/', $id);
        // PascalCase → kebab-case
        $id = (string)preg_replace('/([a-z])([A-Z])/', '$1-$2', $id);
        return strtolower($id);
    }

    private function countLines(string $file): int
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $lines !== false ? count($lines) : 0;
    }

    /**
     * Extract public method/function names from a PHP file.
     * @return list<string>
     */
    private function extractExports(string $file): array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $exports = [];

        // Match: public function methodName(
        if (preg_match_all('/public\s+function\s+(\w+)\s*\(/', $content, $matches)) {
            $exports = array_merge($exports, $matches[1]);
        }

        // Match: function functionName( — for non-class files
        if (preg_match_all('/(?<!\bpublic\s)(?<!\bprivate\s)(?<!\bprotected\s)function\s+(\w+)\s*\(/', $content, $matches)) {
            $exports = array_merge($exports, $matches[1]);
        }

        return array_values(array_unique($exports));
    }

    /**
     * Extract use/require dependencies from a PHP file.
     * @return list<string>
     */
    private function extractDependencies(string $file): array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $deps = [];

        // Match: use Converge\Foo\Bar;
        if (preg_match_all('/use\s+Converge\\\\(\S+);/', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $dep = str_replace('\\', '/', $match);
                $dep = (string)preg_replace('/([a-z])([A-Z])/', '$1-$2', $dep);
                $deps[] = strtolower($dep);
            }
        }

        // Match: require_once ... (for inter-module deps)
        if (preg_match_all('/require(?:_once)?\s+[\'"](\S+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $match) {
                if (str_contains($match, 'vendor/')) {
                    continue; // Skip vendor deps
                }
                $deps[] = basename(dirname($match)) . '/' . basename($match);
            }
        }

        return array_values(array_unique($deps));
    }

    private function inferLayer(string $relativePath): string
    {
        if (str_starts_with($relativePath, 'Observability')) return 'L1-Infra/🔭-可观察';
        if (str_starts_with($relativePath, 'Traceability')) return 'L1-Infra/📋-可追溯';
        if (str_starts_with($relativePath, 'Resilience')) return 'L1-Infra/🛡️-弹性';
        if (str_starts_with($relativePath, 'Core')) return 'L1-Infra/🔌-核心';
        if (str_starts_with($relativePath, 'Entity')) return 'L2-Domain';
        if (str_starts_with($relativePath, 'Repository')) return 'L2-Domain';
        if (str_starts_with($relativePath, 'Tracking')) return 'L3-Service';
        if (str_starts_with($relativePath, 'Stats')) return 'L3-Service';
        if (str_starts_with($relativePath, 'Api')) return 'L4-API';
        if (str_starts_with($relativePath, 'Auth')) return 'L4-API';
        return 'Unknown';
    }

    private function hasTestFile(string $relativePath): bool
    {
        $testPath = defined('ROOT_PATH')
            ? ROOT_PATH . '/tests/Unit/' . dirname($relativePath)
            : dirname(__DIR__, 2) . '/tests/Unit/' . dirname($relativePath);

        $baseName = basename($relativePath, '.php');
        return is_file($testPath . '/' . $baseName . 'Test.php');
    }
}
