<?php
declare(strict_types=1);

namespace Converge\UI\Blocks;

use Converge\UI\Data\DataSourceRegistry;
use Converge\UI\RenderContext;

/**
 * Card — 数据卡片/指标卡区块
 *
 * 用于 Dashboard 指标展示、统计卡片、数据摘要。
 * 支持静态数据和数据源绑定。自动从 RenderContext 读取租户上下文。
 *
 * Props:
 *   title: string — 卡片标题
 *   value: string|int — 主数值
 *   icon: string — emoji 图标
 *   trend: 'up'|'down'|'flat' — 趋势方向
 *   trendValue: string — 趋势文本（如 "+12%"）
 *   variant: 'default'|'success'|'warning'|'danger'
 *   dataSource: string — 数据源名称（优先级 > value）
 *   dataSourceParams: array — 运行时参数
 */
class Card
{
    public static function render(array $props = []): string
    {
        $title = $props['title'] ?? 'Metric';
        $value = $props['value'] ?? '—';
        $icon = $props['icon'] ?? '';
        $trend = $props['trend'] ?? '';
        $trendValue = $props['trendValue'] ?? '';
        $variant = $props['variant'] ?? 'default';

        // 数据绑定（自动传递 RenderContext）
        $dsName = $props['dataSource'] ?? '';
        if ($dsName) {
            $source = DataSourceRegistry::resolve($dsName);
            if ($source) {
                $ctx = RenderContext::current();
                $rows = $source->fetch($props['dataSourceParams'] ?? [], $ctx);
                if (!empty($rows[0])) {
                    $row = $rows[0];
                    $value = $row['value'] ?? $row[$title] ?? $value;
                    $trendValue = $row['trend'] ?? $row['trendValue'] ?? $trendValue;
                }
            }
        }

        // 变体颜色
        $variantColors = [
            'default' => 'border-accent',
            'success' => 'border-success',
            'warning' => 'border-warning',
            'danger'  => 'border-danger',
        ];
        $borderColor = $variantColors[$variant] ?? $variantColors['default'];

        // 趋势图标
        $trendIcon = match($trend) {
            'up'   => '↑',
            'down' => '↓',
            default => '',
        };
        $trendColor = match($trend) {
            'up'   => 'text-success',
            'down' => 'text-danger',
            default => 'text-content-tertiary',
        };

        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<div class="bg-surface-raised rounded-2xl border-l-4 ' . $borderColor . ' p-5 shadow-sm flex flex-col gap-2 min-w-[160px]">';

        // Header: icon + title
        if ($icon || $title) {
            $html .= '<div class="flex items-center gap-2 text-content-tertiary text-xs font-semibold uppercase tracking-[.05em]">';
            if ($icon) $html .= '<span aria-hidden="true">' . $h($icon) . '</span>';
            $html .= '<span>' . $h($title) . '</span>';
            $html .= '</div>';
        }

        // Value
        $html .= '<div class="text-2xl font-extrabold text-content-primary">' . $h((string)$value) . '</div>';

        // Trend
        if ($trend || $trendValue) {
            $html .= '<div class="flex items-center gap-1 text-xs font-medium ' . $trendColor . '">';
            if ($trendIcon) $html .= '<span>' . $trendIcon . '</span>';
            if ($trendValue) $html .= '<span>' . $h($trendValue) . '</span>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }
}
