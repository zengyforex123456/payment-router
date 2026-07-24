<?php
/**
 * ComponentRegistry — TDA 行为层核心: Alpine 组件自动发现 + 注册表 (A: Action 层)
 *
 * 约定: 所有 Alpine 组件放 resources/js/components/*.js
 * 文件名 = 组件名 (如 dock-sidebar.js → dockNav)
 *
 * 自动发现: 扫描目录 → 正则提取 Alpine.data('name' → registry.json
 * 价值: 新建组件 = 新建文件到约定目录, 零手动注册.
 *
 * 用法:
 *   $components = ComponentRegistry::discover();
 *   ComponentRegistry::verify('dockNav');  // true/false
 *   ComponentRegistry::export();           // → storage/component-registry.json
 */
declare(strict_types=1);

namespace Converge\UI\Engine;

class ComponentRegistry
{
    /** @var array<string> 已注册组件名列表 */
    private static ?array $registry = null;

    /** 组件搜索目录 (按优先级) */
    private const SEARCH_DIRS = [
        'resources/js/components',
        'public/assets/js/components',
        'public/build/js/components',
    ];

    /** 额外文件 (不在约定目录中的全局组件) */
    private const EXTRA_FILES = [
        'public/assets/js/app.js',
    ];

    /** 也扫描模板中的内联 Alpine.data() (TDA 中期会提取到 .js 文件) */
    private const TEMPLATE_DIRS = [
        'templates/pages',
        'templates/_content',
        'templates/_partials',
        'templates/_layouts',
    ];

    // ═══════════════════════════════════════
    // Public API
    // ═══════════════════════════════════════

    /**
     * 自动发现所有已注册的 Alpine 组件.
     *
     * @return array<string> 组件名列表 (已去重)
     */
    public static function discover(): array
    {
        if (self::$registry !== null) return self::$registry;

        $components = [];
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 4);

        // 1. 扫描约定目录
        foreach (self::SEARCH_DIRS as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) continue;
            foreach (glob($path . '/*.js') as $file) {
                $found = self::extractComponents($file);
                $components = array_merge($components, $found);
            }
        }

        // 2. 扫描额外文件
        foreach (self::EXTRA_FILES as $relPath) {
            $file = $root . '/' . $relPath;
            if (file_exists($file)) {
                $found = self::extractComponents($file);
                $components = array_merge($components, $found);
            }
        }

        // 3. 扫描模板中的内联 Alpine.data() 定义 (TDA 中期提取到 .js)
        foreach (self::TEMPLATE_DIRS as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) continue;
            foreach (glob($path . '/*.latte') as $file) {
                $found = self::extractComponents($file);
                $components = array_merge($components, $found);
            }
        }

        self::$registry = array_values(array_unique($components));
        return self::$registry;
    }

    /** 验证组件名是否已注册 */
    public static function verify(string $componentName): bool
    {
        return in_array($componentName, self::discover(), true);
    }

    /** 导出注册表到 JSON 文件 */
    public static function export(string $outputPath): void
    {
        $data = [
            'generated_at' => date('c'),
            'components'   => self::discover(),
            'total'        => count(self::discover()),
        ];
        file_put_contents($outputPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); /* AlpineHelper omit: build artifact */
    }

    /** 获取注册表统计 */
    public static function stats(): array
    {
        return [
            'total'      => count(self::discover()),
            'components' => self::discover(),
        ];
    }

    // ═══════════════════════════════════════
    // Internal
    // ═══════════════════════════════════════

    /** 从 JS/Latte 文件提取 Alpine.data() / Alpine.store() / __alpineQueue 调用 */
    private static function extractComponents(string $file): array
    {
        $content = @file_get_contents($file);
        if (!$content) return [];

        $components = [];

        // Pattern 1: Alpine.data('name'  or  Alpine.store('name'
        if (preg_match_all("/Alpine\.(?:data|store)\s*\(\s*'([a-zA-Z][a-zA-Z0-9]*)'/", $content, $m)) {
            $components = array_merge($components, $m[1]);
        }

        // Pattern 2: __alpineQueue.data('name'  or  window.__alpineQueue.data('name'
        // Pattern 2b: (window.__alpineQueue.data || Alpine.data)('name' — compound expression
        if (preg_match_all("/(?:window\.)?__alpineQueue\.(?:data|store)\s*\(\s*'([a-zA-Z][a-zA-Z0-9]*)'/", $content, $m)) {
            $components = array_merge($components, $m[1]);
        }
        if (preg_match_all("/\(window\.__alpineQueue\.(?:data|store)\s*\|\|\s*Alpine\.(?:data|store)\)\s*\(\s*'([a-zA-Z][a-zA-Z0-9]*)'/", $content, $m)) {
            $components = array_merge($components, $m[1]);
        }

        return $components;
    }
}
