<?php
declare(strict_types=1);

namespace Converge\UI\Blocks;

use Converge\UI\Data\DataSourceRegistry;
use Converge\UI\RenderContext;

/**
 * Table — 数据表格区块
 *
 * Props:
 *   columns: [{key, label, align?, sortable?}]
 *   rows: [{key: value, ...}]
 *   dataSource: string — 数据源名称（优先级 > rows）
 *   dataSourceParams: array — 传给数据源的运行时参数
 *   caption: string (optional)
 *   empty: string — 空数据提示
 *   striped: bool
 *   compact: bool
 *
 * 自动读取 RenderContext::current() 传递给 DataSource，
 * 实现租户隔离和数据权限过滤。
 */
class Table
{
    public static function render(array $props = []): string
    {
        // 数据绑定: dataSource 优先于静态 rows
        $rows = [];
        $columns = [];
        $dsName = $props['dataSource'] ?? '';

        if ($dsName) {
            $source = DataSourceRegistry::resolve($dsName);
            if ($source) {
                $ctx = RenderContext::current();
                $rows = $source->fetch($props['dataSourceParams'] ?? [], $ctx);
                $columns = $source->columns();
            }
        }

        // Fallback: 静态数据
        if (empty($columns)) {
            $columns = $props['columns'] ?? [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'role', 'label' => 'Role'],
                ['key' => 'status', 'label' => 'Status'],
            ];
        }
        if (empty($rows) && !$dsName) {
            $rows = $props['rows'] ?? [
                ['name' => 'Alice Chen', 'email' => 'alice@example.com', 'role' => 'Admin', 'status' => 'Active'],
                ['name' => 'Bob Wang', 'email' => 'bob@example.com', 'role' => 'Editor', 'status' => 'Active'],
                ['name' => 'Carol Li', 'email' => 'carol@example.com', 'role' => 'Viewer', 'status' => 'Inactive'],
            ];
        }

        $caption = $props['caption'] ?? '';
        $empty = $props['empty'] ?? 'No data';
        $striped = (bool)($props['striped'] ?? true);
        $compact = (bool)($props['compact'] ?? false);

        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $py = $compact ? 'py-2' : 'py-3';
        $px = $compact ? 'px-3' : 'px-4';

        $html = '<div class="overflow-x-auto rounded-xl border bg-surface-raised shadow-sm">';
        $html .= '<table class="w-full border-collapse text-sm">';

        if ($caption) {
            $html .= '<caption class="text-xs text-content-tertiary px-4 py-2 text-left">' . $h($caption) . '</caption>';
        }

        // Header
        $html .= '<thead><tr class="bg-surface-overlay border-b">';
        foreach ($columns as $col) {
            $align = ($col['align'] ?? 'left') === 'right' ? 'text-right' : (($col['align'] ?? 'left') === 'center' ? 'text-center' : 'text-left');
            $html .= '<th class="' . $py . ' ' . $px . ' ' . $align . ' text-xs font-bold uppercase tracking-[.05em] text-content-tertiary">' . $h($col['label']) . '</th>';
        }
        $html .= '</tr></thead>';

        // Body
        $html .= '<tbody>';
        if (empty($rows)) {
            $colspan = count($columns);
            $html .= '<tr><td colspan="' . $colspan . '" class="' . $py . ' ' . $px . ' text-center text-content-tertiary text-sm">' . $h($empty) . '</td></tr>';
        } else {
            $rowIdx = 0;
            foreach ($rows as $row) {
                $bg = ($striped && $rowIdx % 2 === 1) ? 'bg-surface-overlay/50' : '';
                $html .= '<tr class="border-t ' . $bg . ' hover:bg-surface-overlay transition">';
                foreach ($columns as $col) {
                    $key = $col['key'] ?? '';
                    $align = ($col['align'] ?? 'left') === 'right' ? 'text-right' : (($col['align'] ?? 'left') === 'center' ? 'text-center' : 'text-left');
                    $val = $row[$key] ?? '';
                    // Status badge styling
                    if ($key === 'status') {
                        $statusColor = match(strtolower((string)$val)) {
                            'active', 'success', 'done', 'completed' => 'bg-success-soft text-success',
                            'inactive', 'pending' => 'bg-warning-soft text-warning',
                            'error', 'failed', 'blocked' => 'bg-danger-soft text-danger',
                            default => 'bg-surface-overlay text-content-secondary',
                        };
                        $val = '<span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold ' . $statusColor . '">' . $h((string)$val) . '</span>';
                    } else {
                        $val = $h((string)$val);
                    }
                    $html .= '<td class="' . $py . ' ' . $px . ' ' . $align . ' text-content-secondary">' . $val . '</td>';
                }
                $html .= '</tr>';
                $rowIdx++;
            }
        }
        $html .= '</tbody></table></div>';

        return $html;
    }
}
