<?php

declare(strict_types=1);

namespace Converge\UI\Legacy\Molecules;

/**
 * DataTable — sortable data table molecule.
 *
 * Composes: pure PHP table with design-token CSS classes.
 * Zero raw HTML in call sites. Columns defined by config array.
 *
 * Usage:
 *   echo DataTable::render([
 *       'columns' => ['Name', 'Status', 'Conversions', 'Spend'],
 *       'rows' => [
 *           ['Campaign A', 'Active', '1,234', '$567'],
 *           ['Campaign B', 'Paused', '89', '$12'],
 *       ],
 *   ]);
 */
class DataTable
{
    /**
     * @param array $config {
     *   columns: array       — column header labels
     *   rows: array          — array of row arrays
     *   emptyText: string    — shown when rows is empty
     *   class: string        — extra wrapper class
     *   headerClass: string  — extra header class
     *   onRowClick: string   — JS onclick for rows (receives row index)
     * }
     */
    public static function render(array $config): string
    {
        $columns    = $config['columns'] ?? [];
        $rows       = $config['rows'] ?? [];
        $emptyText  = $config['emptyText'] ?? 'No data';
        $class      = $config['class'] ?? '';
        $headerClass = $config['headerClass'] ?? '';
        $onRowClick = $config['onRowClick'] ?? null;

        if (empty($rows)) {
            return '<div class="text-center py-12 text-content-tertiary text-sm">'
                . htmlspecialchars($emptyText, ENT_QUOTES, 'UTF-8')
                . '</div>';
        }

        $html = '<div class="overflow-x-auto ' . $class . '">'
            . '<table class="w-full border-collapse text-sm">'
            . '<thead>'
            . '<tr class="bg-surface-overlay border-b border-default">';

        foreach ($columns as $col) {
            $html .= '<th class="text-left px-4 py-3 text-xs font-semibold text-content-tertiary uppercase tracking-wide ' . $headerClass . '">'
                . htmlspecialchars((string)$col, ENT_QUOTES, 'UTF-8')
                . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $i => $row) {
            $clickAttr = '';
            if ($onRowClick !== null) {
                $clickAttr = ' onclick="' . str_replace('{index}', (string)$i, $onRowClick) . '"';
            }
            $html .= '<tr class="border-b border-default hover:bg-accent-soft transition-colors cursor-pointer"' . $clickAttr . '>';
            foreach ($row as $cell) {
                $html .= '<td class="px-4 py-3 text-content-primary">'
                    . (is_string($cell) ? $cell : (string)$cell)
                    . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }
}
