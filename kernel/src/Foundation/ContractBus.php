<?php
declare(strict_types=1);

namespace Converge\Foundation;

/**
 * ContractBus — 模块契约总线
 *
 * 模块间不直接 use 对方的具体类，而是通过契约接口调用。
 * ContractBus 在启动时扫描模块注册，运行时解析接口→实现。
 *
 * 用法:
 *   ContractBus::register(ClickRecorderInterface::class, ClickRecorder::class);
 *   $recorder = ContractBus::get(ClickRecorderInterface::class);
 *   $recorder->record(...);
 */
class ContractBus
{
    /** @var array<string, string> interface → implementation */
    private static array $bindings = [];

    /** @var array<string, object> singleton cache */
    private static array $instances = [];

    /**
     * 注册: 接口 → 类名 (无参构造)
     */
    public static function register(string $interface, string $implementation): void
    {
        self::$bindings[$interface] = $implementation;
    }

    /**
     * 注册: 接口 → 工厂闭包 (支持构造参数)
     * 例: ContractBus::factory(ClickRecorderInterface::class, fn() => new ClickRecorder($db, $settings));
     */
    public static function factory(string $interface, \Closure $factory): void
    {
        self::$bindings[$interface] = $factory;
    }

    /**
     * 获取契约实现（单例）
     */
    public static function get(string $interface): object
    {
        if (isset(self::$instances[$interface])) {
            return self::$instances[$interface];
        }

        $binding = self::$bindings[$interface] ?? null;
        if (!$binding) {
            throw new \RuntimeException("ContractBus: no implementation for {$interface}");
        }

        if ($binding instanceof \Closure) {
            self::$instances[$interface] = $binding();
        } else {
            self::$instances[$interface] = new $binding();
        }
        return self::$instances[$interface];
    }

    /**
     * 检查是否有实现
     */
    public static function has(string $interface): bool
    {
        return isset(self::$bindings[$interface]);
    }

    /**
     * 清空（测试用）
     */
    public static function reset(): void
    {
        self::$bindings = [];
        self::$instances = [];
    }
}
