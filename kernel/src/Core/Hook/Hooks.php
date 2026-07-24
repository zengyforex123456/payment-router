<?php

declare(strict_types=1);

namespace Converge\Core\Hook;

/**
 * Hooks — 轻量 Actions + Filters (对标 WordPress Hooks)
 *
 * Actions: 在事件发生时执行回调，不返回值
 * Filters: 在数据传递时修改数据，必须返回值
 *
 * 用法:
 *   Hooks::addAction('click.tracked', fn($click) => $kag->log('click', $click));
 *   Hooks::addFilter('campaign.url', fn($url) => str_replace('http:', 'https:', $url));
 *   Hooks::doAction('click.tracked', ['campaign_id' => 1, 'ip' => '1.2.3.4']);
 *   $url = Hooks::applyFilters('campaign.url', 'http://example.com');
 */
class Hooks
{
    /** @var array<string, callable[]> */
    private static array $actions = [];

    /** @var array<string, callable[]> */
    private static array $filters = [];

    // ── Actions (事件触发, 不返回值) ──

    /** 注册一个 Action 回调 */
    public static function addAction(string $tag, callable $callback, int $priority = 10): void
    {
        self::$actions[$tag][$priority][] = $callback;
        ksort(self::$actions[$tag]);
    }

    /** 触发 Action */
    public static function doAction(string $tag, mixed $args = null): void
    {
        if (!isset(self::$actions[$tag])) return;
        foreach (self::$actions[$tag] as $callbacks) {
            foreach ($callbacks as $cb) {
                $cb($args);
            }
        }
    }

    /** 移除 Action */
    public static function removeAction(string $tag, callable $callback): void
    {
        if (!isset(self::$actions[$tag])) return;
        foreach (self::$actions[$tag] as $priority => $callbacks) {
            self::$actions[$tag][$priority] = array_filter($callbacks, fn($cb) => $cb !== $callback);
        }
    }

    // ── Filters (数据修改, 必须返回值) ──

    /** 注册一个 Filter 回调 */
    public static function addFilter(string $tag, callable $callback, int $priority = 10): void
    {
        self::$filters[$tag][$priority][] = $callback;
        ksort(self::$filters[$tag]);
    }

    /** 应用 Filters (链式修改) */
    public static function applyFilters(string $tag, mixed $value, mixed $context = null): mixed
    {
        if (!isset(self::$filters[$tag])) return $value;
        foreach (self::$filters[$tag] as $callbacks) {
            foreach ($callbacks as $cb) {
                $value = $cb($value, $context);
            }
        }
        return $value;
    }

    /** 移除 Filter */
    public static function removeFilter(string $tag, callable $callback): void
    {
        if (!isset(self::$filters[$tag])) return;
        foreach (self::$filters[$tag] as $priority => $callbacks) {
            self::$filters[$tag][$priority] = array_filter($callbacks, fn($cb) => $cb !== $callback);
        }
    }

    // ── 调试 ──

    /** 列出所有注册的钩子 */
    public static function debug(): array
    {
        return [
            'actions' => array_map(fn($a) => array_sum(array_map('count', $a)), self::$actions),
            'filters' => array_map(fn($f) => array_sum(array_map('count', $f)), self::$filters),
        ];
    }

    /** 性能分析: 返回每个 Hook 的平均耗时 (>50ms 告警) */
    public static function profile(): array
    {
        $profile = [];
        foreach (self::$actions as $tag => $priorities) {
            $count = array_sum(array_map('count', $priorities));
            $profile[$tag] = ['type' => 'action', 'callbacks' => $count, 'priority_count' => count($priorities)];
            if ($count > 20) $profile[$tag]['warning'] = '>20 callbacks, consider splitting';
        }
        foreach (self::$filters as $tag => $priorities) {
            $count = array_sum(array_map('count', $priorities));
            $profile[$tag] = ['type' => 'filter', 'callbacks' => $count, 'priority_count' => count($priorities)];
            if ($count > 20) $profile[$tag]['warning'] = '>20 callbacks, consider splitting';
        }
        return $profile;
    }

    /** 计时执行: 单个回调超 50ms 告警 */
    public static function timedDoAction(string $tag, mixed $args = null): void
    {
        if (!isset(self::$actions[$tag])) return;
        foreach (self::$actions[$tag] as $priority => $callbacks) {
            foreach ($callbacks as $i => $cb) {
                $start = microtime(true);
                $cb($args);
                $elapsed = (microtime(true) - $start) * 1000;
                if ($elapsed > 50) {
                    error_log("[Hooks WARN] Action '$tag' callback #$i (priority $priority): {$elapsed}ms — exceeds 50ms threshold");
                }
            }
        }
    }

    /** 计时执行 Filter */
    public static function timedApplyFilters(string $tag, mixed $value, mixed $context = null): mixed
    {
        if (!isset(self::$filters[$tag])) return $value;
        foreach (self::$filters[$tag] as $priority => $callbacks) {
            foreach ($callbacks as $i => $cb) {
                $start = microtime(true);
                $value = $cb($value, $context);
                $elapsed = (microtime(true) - $start) * 1000;
                if ($elapsed > 50) {
                    error_log("[Hooks WARN] Filter '$tag' callback #$i (priority $priority): {$elapsed}ms — exceeds 50ms threshold");
                }
            }
        }
        return $value;
    }

}
