<?php
declare(strict_types=1);

namespace Converge\UI\Data;

/**
 * DataSourceRegistry — 数据源注册表（全局单例）
 *
 * 模块在 bootstrap 中注册数据源，区块通过名称引用。
 * Builder JSON 只存名称字符串，不存 SQL/代码 — 安全可控。
 *
 * 用法:
 *   // 注册（在 bootstrap.php 或 init 中）
 *   DataSourceRegistry::register('users', new MysqlDataSource($db, 'SELECT ...', 'i', [...columns]));
 *
 *   // 解析（在区块 render 中自动调用）
 *   $source = DataSourceRegistry::resolve('users');
 *   $rows = $source->fetch(['tenant_id' => $tenantId]);
 *
 *   // 列出（供 Builder 下拉选择）
 *   $list = DataSourceRegistry::list();
 */
class DataSourceRegistry
{
    /** @var array<string, DataSourceInterface> */
    private static array $sources = [];

    /**
     * 注册数据源
     * @throws \RuntimeException 如果名称已存在
     */
    public static function register(string $name, DataSourceInterface $source): void
    {
        if (isset(self::$sources[$name])) {
            throw new \RuntimeException("DataSource '{$name}' already registered");
        }
        self::$sources[$name] = $source;
    }

    /**
     * 覆盖注册（允许替换已有数据源，用于测试/多租户切换）
     */
    public static function override(string $name, DataSourceInterface $source): void
    {
        self::$sources[$name] = $source;
    }

    /**
     * 解析数据源
     */
    public static function resolve(string $name): ?DataSourceInterface
    {
        return self::$sources[$name] ?? null;
    }

    /**
     * 列出所有已注册数据源名称
     * @return array<int, string>
     */
    public static function list(): array
    {
        return array_keys(self::$sources);
    }

    /**
     * 列出所有已注册数据源（含列信息，供 Builder 使用）
     * @return array<int, array{name:string, columns:array}>
     */
    public static function listDetailed(): array
    {
        $list = [];
        foreach (self::$sources as $name => $source) {
            $list[] = [
                'name'    => $name,
                'columns' => $source->columns(),
            ];
        }
        return $list;
    }

    /**
     * 检查数据源是否存在
     */
    public static function has(string $name): bool
    {
        return isset(self::$sources[$name]);
    }

    /**
     * 清空注册表（仅测试用）
     */
    public static function reset(): void
    {
        self::$sources = [];
    }
}
