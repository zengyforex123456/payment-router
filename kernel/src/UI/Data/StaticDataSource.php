<?php
declare(strict_types=1);

namespace Converge\UI\Data;

use Converge\UI\RenderContext;

/**
 * StaticDataSource — 静态数组数据源
 *
 * 封装硬编码数据，形式化当前 Table/block 的默认数据行为。
 * 用于 demo 数据、配置常量、或无需数据库的简单列表。
 *
 * 用法:
 *   new StaticDataSource(
 *       [['name' => 'Alice', 'role' => 'Admin'], ...],
 *       [['key' => 'name', 'label' => 'Name'], ['key' => 'role', 'label' => 'Role']]
 *   )
 */
class StaticDataSource implements DataSourceInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $data;

    /** @var array<int, array{key:string, label:string, align?:string, sortable?:bool}> */
    private array $cols;

    /**
     * @param array<int, array<string, mixed>> $data  数据行
     * @param array<int, array{key:string, label:string, align?:string, sortable?:bool}> $cols 列定义（为空时自动从第一行 key 推断）
     */
    public function __construct(array $data, array $cols = [])
    {
        $this->data = $data;
        $this->cols = $cols;
    }

    public function fetch(array $params = [], ?RenderContext $ctx = null): array
    {
        return $this->data;
    }

    public function columns(): array
    {
        if (!empty($this->cols)) {
            return $this->cols;
        }
        // 自动从第一行数据 key 推断列
        if (empty($this->data[0])) {
            return [];
        }
        $cols = [];
        foreach (array_keys($this->data[0]) as $key) {
            $cols[] = ['key' => $key, 'label' => ucfirst($key)];
        }
        return $cols;
    }
}
