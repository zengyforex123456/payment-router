<?php
/**
 * PageContract — 页面数据契约校验 (开发期执行, 生产环境跳过)
 *
 * 目的: 让 CLAUDE.md 里写的契约变成可执行校验。
 *       Controller 传错数据 → APP_DEBUG 下立刻抛异常, 不静默失败。
 *
 * 用法:
 *   $data = ['kpis' => [...], 'statCardsHtml' => $html];
 *   PageContract::check('dashboard', $data);
 *   LatteEngine::display('pages/dashboard', $data);
 *
 * 契约定义: contracts/pages/{page}.php — 返回 schema 数组
 *
 * 性能: 生产环境 (APP_DEBUG=false) 直接跳过 — 零开销。
 */
declare(strict_types=1);

namespace Converge\Foundation\Contract;

use RuntimeException;

class PageContract
{
    /** @var array<string, array> 已加载的契约缓存 */
    private static array $schemas = [];

    /**
     * 校验页面数据是否符合契约。
     * APP_DEBUG=false 时直接跳过（零生产开销）。
     *
     * @throws RuntimeException 数据不符合契约
     */
    public static function check(string $page, array $data): void
    {
        if (!(defined('APP_DEBUG') && APP_DEBUG)) return;

        $schema = self::loadSchema($page);
        if (!$schema) return; // 无契约定义 → 跳过

        self::validateStruct($data, $schema, $page);
    }

    // ═══ Schema 加载 ═══

    private static function loadSchema(string $page): ?array
    {
        if (isset(self::$schemas[$page])) return self::$schemas[$page];

        $file = APP_ROOT . "/contracts/pages/{$page}.php";
        if (!file_exists($file)) return null;

        $schema = require $file;
        self::$schemas[$page] = $schema;
        return $schema;
    }

    // ═══ 递归结构校验 ═══

    private static function validateStruct(array $data, array $schema, string $path): void
    {
        foreach ($schema as $key => $rule) {
            // 必填检查
            $required = !str_ends_with((string)$key, '?');
            $realKey  = $required ? (string)$key : rtrim((string)$key, '?');

            if ($required && !array_key_exists($realKey, $data)) {
                throw new RuntimeException(
                    "PageContract [{$path}]: 缺少必填字段 '{$realKey}'"
                );
            }
            if (!array_key_exists($realKey, $data)) continue;

            $value = $data[$realKey];

            // 类型检查
            if (is_array($rule) && isset($rule['type'])) {
                self::checkType($value, $rule['type'], "{$path}.{$realKey}");
            }

            // 嵌套结构 (如 kpis 是数组, items 是对象数组)
            if (is_array($rule) && isset($rule['items']) && is_array($value)) {
                if (array_is_list($value)) {
                    $i = 0;
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            self::validateStruct($item, $rule['items'], "{$path}.{$realKey}[{$i}]");
                        }
                        $i++;
                    }
                } else {
                    self::validateStruct($value, $rule['items'], "{$path}.{$realKey}");
                }
            }
        }
    }

    private static function checkType(mixed $value, string $type, string $path): void
    {
        $valid = match ($type) {
            'string'    => is_string($value),
            'int'       => is_int($value),
            'float'     => is_float($value) || is_int($value),
            'bool'      => is_bool($value),
            'array'     => is_array($value),
            'string|int' => is_string($value) || is_int($value),
            'string|int|float' => is_string($value) || is_int($value) || is_float($value),
            '?string'   => $value === null || is_string($value),
            '?int'      => $value === null || is_int($value),
            default     => true,
        };

        if (!$valid) {
            $actual = get_debug_type($value);
            throw new RuntimeException(
                "PageContract [{$path}]: 类型错误 — 期望 {$type}, 实际 {$actual}"
            );
        }
    }

    /**
     * 列出所有已定义契约的页面 (调试用)
     * @return string[]
     */
    public static function listContracts(): array
    {
        $dir = APP_ROOT . '/contracts/pages/';
        if (!is_dir($dir)) return [];

        $pages = [];
        foreach (glob($dir . '*.php') as $file) {
            $pages[] = basename($file, '.php');
        }
        return $pages;
    }
}
