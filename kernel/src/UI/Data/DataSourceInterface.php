<?php
declare(strict_types=1);

namespace Converge\UI\Data;

use Converge\UI\RenderContext;

/**
 * DataSourceInterface — 区块数据绑定契约
 *
 * 所有数据源必须实现此接口。数据源在 PHP 代码中注册（安全可控），
 * Builder JSON 仅通过名称引用。
 *
 * 用法:
 *   $source = DataSourceRegistry::resolve('users');
 *   $rows = $source->fetch(params: ['page' => 1], ctx: RenderContext::current());
 *   $cols = $source->columns();
 */
interface DataSourceInterface
{
    /**
     * 获取数据行
     * @param array<string, mixed> $params 运行时参数（分页、过滤等）
     * @param RenderContext|null   $ctx    渲染上下文（用于自动租户过滤）
     * @return array<int, array<string, mixed>> 数据行数组
     */
    public function fetch(array $params = [], ?RenderContext $ctx = null): array;

    /**
     * 返回列元数据（供 Table 等区块自动生成表头）
     * @return array<int, array{key:string, label:string, align?:string, sortable?:bool}>
     */
    public function columns(): array;
}
