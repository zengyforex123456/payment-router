<?php
declare(strict_types=1);

namespace Converge\UI\Data;

use Converge\UI\RenderContext;

/**
 * MysqlDataSource — MySQL 查询数据源
 *
 * 参数化查询，防 SQL 注入。自动从 RenderContext 注入 tenant_id 实现租户隔离。
 *
 * 用法:
 *   // SQL 中用 {tenant} 占位符 → 自动替换为 $ctx->tenantId
 *   new MysqlDataSource(
 *       $mysqli,
 *       'SELECT id, name, email FROM users WHERE tenant_id = {tenant} ORDER BY created_at DESC',
 *       '',  // 额外参数类型码
 *       [['key' => 'name', 'label' => 'Name'], ...]
 *   )
 *
 *   // 或者手动传 tenant_id:
 *   new MysqlDataSource(
 *       $mysqli,
 *       'SELECT * FROM users WHERE tenant_id = ?',
 *       'i',
 *       [...]
 *   )
 */
class MysqlDataSource implements DataSourceInterface
{
    private \mysqli $db;
    private string $sql;
    private string $types;
    /** @var array<int, array{key:string, label:string, align?:string, sortable?:bool}> */
    private array $cols;

    /**
     * @param \mysqli $db    MySQL 连接
     * @param string  $sql   参数化 SQL（? 占位符）
     * @param string  $types 参数类型码（'i'=int, 's'=string, 'd'=double, 'b'=blob）
     * @param array   $cols  列定义
     */
    public function __construct(\mysqli $db, string $sql, string $types = '', array $cols = [])
    {
        $this->db = $db;
        $this->sql = $sql;
        $this->types = $types;
        $this->cols = $cols;
    }

    public function fetch(array $params = [], ?RenderContext $ctx = null): array
    {
        $sql = $this->sql;

        // 自动注入 tenant_id（{tenant} 占位符）
        if ($ctx && $ctx->tenantId !== null && str_contains($sql, '{tenant}')) {
            $sql = str_replace('{tenant}', (string)$ctx->tenantId, $sql);
        }

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log('[MysqlDataSource] prepare failed: ' . $this->db->error);
            return [];
        }

        // 绑定额外参数（跳过已注入的 {tenant}）
        $values = array_values($params);
        if (!empty($this->types) && !empty($values)) {
            $stmt->bind_param($this->types, ...$values);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            return [];
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows ?: [];
    }

    public function columns(): array
    {
        if (!empty($this->cols)) {
            return $this->cols;
        }
        // 无列定义时返回空 — 调用方使用自己的默认列
        return [];
    }
}
