<?php
declare(strict_types=1);

namespace Converge\UI\Data;

use Converge\UI\RenderContext;

/**
 * CallbackDataSource — PHP 回调数据源
 *
 * 最灵活的数据源：任意 PHP callable，接收 params + context，返回 rows。
 * 适合：API 聚合、缓存层、复杂业务逻辑、跨表查询。
 *
 * 用法:
 *   new CallbackDataSource(
 *       function (array $params, ?RenderContext $ctx): array {
 *           $tenantId = $ctx?->tenantId;
 *           return fetchFromApi($tenantId, $params);
 *       },
 *       [['key' => 'name', 'label' => 'Name'], ['key' => 'role', 'label' => 'Role']]
 *   )
 */
class CallbackDataSource implements DataSourceInterface
{
    /** @var callable */
    private $callable;

    /** @var array<int, array{key:string, label:string, align?:string, sortable?:bool}> */
    private array $cols;

    /**
     * @param callable $callable function(array $params, ?RenderContext $ctx): array — 返回数据行
     * @param array    $cols     列定义
     */
    public function __construct(callable $callable, array $cols = [])
    {
        $this->callable = $callable;
        $this->cols = $cols;
    }

    public function fetch(array $params = [], ?RenderContext $ctx = null): array
    {
        return ($this->callable)($params, $ctx);
    }

    public function columns(): array
    {
        return $this->cols;
    }
}
