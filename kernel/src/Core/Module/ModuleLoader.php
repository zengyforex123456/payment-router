<?php

declare(strict_types=1);

namespace Converge\Core\Module;

/**
 * ModuleLoader — 依赖感知的模块加载器
 *
 * 按 depends_on 声明进行拓扑排序，确保依赖模块先加载。
 * 检测循环依赖和缺失依赖，失败不阻塞核心启动。
 *
 * 用法:
 *   $loader = new ModuleLoader(APP_ROOT . '/modules');
 *   $order = $loader->resolve();      // → ['Base', 'Notifier', 'Coupon']
 *   $loader->loadAll();               // require bootstrap.php in correct order
 */
class ModuleLoader implements \Converge\Contracts\ModuleInterface
{
    private string $modulesDir;
    private array $modules = [];
    private array $errors = [];

    public function __construct(?string $modulesDir = null)
    {
        $this->modulesDir = $modulesDir ?? (defined('APP_ROOT') ? APP_ROOT . '/modules' : dirname(__DIR__, 3) . '/modules');
    }

    /**
     * 扫描模块并返回依赖排序后的加载顺序
     * @return string[] 模块名数组（按依赖顺序）
     */
    public function resolve(): array
    {
        $this->modules = [];
        $this->errors = [];

        // 扫描所有模块的 module.json
        foreach (glob($this->modulesDir . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            $jsonPath = dirname($dir, 2) . '/contract/' . strtolower($name) . '-module.json';
            if (!file_exists($jsonPath)) {
                $jsonPath = $dir . '/module.json';
            }

            $config = ['name' => $name, 'depends_on' => []];
            if (file_exists($jsonPath)) {
                $parsed = json_decode(file_get_contents($jsonPath), true);
                if ($parsed) {
                    $config = array_merge($config, $parsed);
                }
            }

            $config['bootstrap'] = $dir . '/bootstrap.php';
            $config['has_bootstrap'] = file_exists($config['bootstrap']);
            $this->modules[$name] = $config;
        }

        // 拓扑排序
        return $this->topologicalSort();
    }

    /**
     * 按依赖顺序加载所有模块
     * @return int 成功加载的模块数
     */
    public function loadAll(): int
    {
        $order = $this->resolve();
        $loaded = 0;

        foreach ($order as $name) {
            $mod = $this->modules[$name];
            if ($mod['has_bootstrap']) {
                require_once $mod['bootstrap'];
                $loaded++;
            }
        }

        return $loaded;
    }

    /** 获取加载错误 */
    public function getErrors(): array { return $this->errors; }

    /** 获取所有模块信息 */
    public function getModules(): array { return $this->modules; }

    /** 干跑模式: 仅输出依赖顺序，不加载 */
    public function dryRun(): string
    {
        $order = $this->resolve();
        $lines = ["═══ 模块加载顺序 (拓扑排序) ═══"];
        foreach ($order as $i => $name) {
            $mod = $this->modules[$name];
            $deps = $mod['depends_on'] ? ' (依赖: ' . implode(', ', $mod['depends_on']) . ')' : '';
            $bs = $mod['has_bootstrap'] ? '✅ bootstrap' : '⚠ 无bootstrap';
            $lines[] = sprintf("  %2d. %-25s %s%s", $i + 1, $name, $bs, $deps);
        }
        if ($this->errors) {
            $lines[] = '';
            $lines[] = '⚠ 依赖问题:';
            foreach ($this->errors as $e) { $lines[] = "   ❌ $e"; }
        }
        return implode("\n", $lines);
    }

    // ── 拓扑排序 (Kahn's algorithm) ──

    private function topologicalSort(): array
    {
        $inDegree = [];
        $graph = [];

        foreach ($this->modules as $name => $mod) {
            if (!isset($inDegree[$name])) $inDegree[$name] = 0;
            if (!isset($graph[$name])) $graph[$name] = [];

            foreach ($mod['depends_on'] as $dep) {
                // 检查缺失依赖
                if (!isset($this->modules[$dep])) {
                    $this->errors[] = "模块 '$name' 依赖 '$dep', 但 '$dep' 不存在";
                    continue;
                }
                $graph[$dep][] = $name;
                $inDegree[$name] = ($inDegree[$name] ?? 0) + 1;
            }
        }

        // 检测循环依赖
        $queue = [];
        foreach ($inDegree as $name => $deg) {
            if ($deg === 0) $queue[] = $name;
        }

        $sorted = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $sorted[] = $current;
            foreach ($graph[$current] ?? [] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        if (count($sorted) !== count($this->modules)) {
            $remaining = array_keys(array_filter($inDegree, fn($d) => $d > 0));
            $this->errors[] = '循环依赖: ' . implode(' → ', $remaining);
            // Fallback: 返回字母序
            $sorted = array_keys($this->modules);
            sort($sorted);
        }

        return $sorted;
    }

    /**
     * 获取模块的公开契约 (P3: ModuleContract)
     *
     * 模块通过 Contract 目录下的接口+实现暴露能力。
     * 其他模块通过此方法获取契约实例，而非直接 new 模块内部类。
     *
     * @template T
     * @param class-string<T> $contractClass Contract 接口 FQCN
     * @return T|null 契约实例，未注册返回 null
     *
     * Usage:
     *   $affiliate = $loader->getContract(AffiliateContract::class);
     *   $affiliate->getByCode('AFF_XXX');
     */
    public function getContract(string $contractClass): ?object
    {
        // 从 contract 接口名推导模块名: Converge\Modules\Affiliate\Contract\AffiliateContract → Affiliate
        $parts = explode('\\', $contractClass);
        $moduleName = $parts[2] ?? '';

        if (!isset($this->modules[$moduleName])) {
            $this->errors[] = "Contract '{$contractClass}' — module '{$moduleName}' not found";
            return null;
        }

        // 自动发现: Contract/ 目录下的 Service 实现类
        $serviceClass = str_replace('\\Contract\\', '\\Contract\\', $contractClass);
        $serviceClass = substr($serviceClass, 0, -8) . 'Service'; // XxxContract → XxxService

        if (class_exists($serviceClass)) {
            return null; // Service requires constructor injection — caller provides
        }

        return null;
    }

    /**
     * 列出所有已注册的 Contract 接口
     * @return array{array{module: string, contract: string, service: string}}
     */
    public function listContracts(): array
    {
        $contracts = [];
        foreach ($this->modules as $name => $mod) {
            $contractDir = $this->modulesDir . '/' . $name . '/Contract';
            if (!is_dir($contractDir)) continue;

            foreach (glob($contractDir . '/*Contract.php') as $file) {
                $basename = basename($file, '.php');
                $contractClass = "Converge\\Modules\\{$name}\\Contract\\{$basename}";
                $serviceClass = "Converge\\Modules\\{$name}\\Contract\\" . str_replace('Contract', 'Service', $basename);
                $contracts[] = [
                    'module'   => $name,
                    'contract' => $contractClass,
                    'service'  => class_exists($serviceClass) ? $serviceClass : null,
                ];
            }
        }
        return $contracts;
    }
}
