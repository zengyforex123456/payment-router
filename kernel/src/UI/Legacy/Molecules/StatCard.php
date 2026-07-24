<?php

declare(strict_types=1);

namespace Converge\UI\Legacy\Molecules;

use Converge\UI\Grid;

/**
 * StatCard — KPI metric card molecule.
 *
 * Composes: Grid::col + icon + value + label + optional trend indicator.
 * Zero raw HTML in call sites — pure component composition.
 *
 * Usage:
 *   echo StatCard::render([
 *       'label' => 'Total Campaigns',
 *       'value' => '142',
 *       'icon'  => '📊',
 *       'trend' => '+12% vs last week',
 *       'trendUp' => true,
 *   ]);
 */
class StatCard
{
    /**
     * @param array $stat {
     *   label: string      — metric label (e.g. "Total Revenue")
     *   value: string|int  — displayed value
     *   icon: string       — emoji or icon HTML
     *   iconBg: string     — Tailwind background class (default: bg-accent-soft)
     *   trend: string|null — optional trend text (e.g. "+12%")
     *   trendUp: bool      — true = green, false = red
     *   span: int          — grid column span (default: 3)
     *   md: int            — responsive md span
     * }
     */
    public static function render(array $stat): string
    {
        $label   = $stat['label'] ?? '';
        $value   = $stat['value'] ?? '';
        $icon    = $stat['icon'] ?? '';
        $iconBg  = $stat['iconBg'] ?? 'bg-accent-soft';
        $trend   = $stat['trend'] ?? null;
        $trendUp = $stat['trendUp'] ?? true;
        $span    = $stat['span'] ?? 3;
        $md      = $stat['md'] ?? null;

        $trendHtml = '';
        if ($trend !== null) {
            $color = $trendUp ? 'text-success' : 'text-danger';
            $arrow = $trendUp ? '↑' : '↓';
            $trendHtml = "<span class=\"{$color} text-xs font-medium\">{$arrow} {$trend}</span>";
        }

        $inner = "<div class=\"flex items-start justify-between\">"
            . "<div>"
            . "<p class=\"text-sm font-medium text-content-secondary\">{$label}</p>"
            . "<p class=\"text-2xl font-bold text-content-primary mt-1\">{$value}</p>"
            . ($trendHtml ? "<p class=\"mt-1\">{$trendHtml}</p>" : '')
            . "</div>"
            . ($icon ? "<div class=\"p-3 rounded-full {$iconBg} flex items-center justify-center text-xl\">{$icon}</div>" : '')
            . "</div>";

        $props = ['span' => $span, 'class' => 'bg-surface-raised rounded-2xl p-6 border hover:border-strong transition-all duration-300'];
        if ($md !== null) {
            $props['md'] = $md;
        }

        return Grid::col($inner, $props);
    }

    /**
     * Render a row of stat cards.
     *
     * @param array $stats Array of stat arrays (see render())
     * @param array $rowProps gap, class (passed to Grid::row)
     */
    public static function row(array $stats, array $rowProps = []): string
    {
        $cols = array_map(fn(array $s): string => self::render($s), $stats);
        return Grid::row($cols, array_merge(['gap' => 'gap-6'], $rowProps));
    }
}
