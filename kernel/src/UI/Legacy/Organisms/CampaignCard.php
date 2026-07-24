<?php

declare(strict_types=1);

namespace Converge\UI\Legacy\Organisms;

use Converge\UI\Badge;
use Converge\UI\Button;
use Converge\UI\Grid;

/**
 * CampaignCard — campaign summary card organism.
 *
 * Composes icon + name + status Badge + metrics + action Button.
 * Uses Grid::col for responsive placement.
 *
 * Status mapping: active→success, paused→warning, completed→info, draft→default
 */
class CampaignCard
{
    private const STATUS_VARIANT = [
        'active' => 'success', 'paused' => 'warning', 'completed' => 'info', 'draft' => 'default',
    ];

    /**
     * @param array $c {
     *   name, status, conversions, spend, icon, onClick, span (default: 4)
     * }
     */
    public static function render(array $c): string
    {
        $name = $c['name'] ?? 'Untitled';
        $status = $c['status'] ?? 'draft';
        $conversions = $c['conversions'] ?? 0;
        $spend = $c['spend'] ?? '$0';
        $icon = $c['icon'] ?? '📢';
        $onClick = $c['onClick'] ?? null;
        $span = $c['span'] ?? 4;

        $badge = Badge::render(ucfirst($status), [
            'variant' => self::STATUS_VARIANT[$status] ?? 'default', 'size' => 'sm',
        ]);
        $btn = Button::render('View', [
            'variant' => 'ghost', 'size' => 'sm', 'onclick' => $onClick,
        ]);
        $click = $onClick ? ' onclick="' . htmlspecialchars($onClick, ENT_QUOTES, 'UTF-8') . '"' : '';

        $inner = '<div class="bg-surface-raised rounded-2xl p-5 border hover:border-strong '
            . 'transition-all duration-300 cursor-pointer"' . $click . '>'
            . '<div class="flex items-center justify-between mb-4">'
            . '<div class="flex items-center gap-3">'
            . '<span class="text-2xl">' . $icon . '</span>'
            . '<h3 class="text-base font-semibold text-content-primary">'
            . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</h3></div>'
            . $badge . '</div>'
            . '<div class="flex items-center justify-between">'
            . '<div><p class="text-sm text-content-secondary">Conversions</p>'
            . '<p class="text-lg font-bold text-content-primary mt-0.5">'
            . number_format($conversions) . '</p></div>'
            . '<div class="text-right"><p class="text-sm text-content-secondary">Spend</p>'
            . '<p class="text-lg font-bold text-content-primary mt-0.5">'
            . htmlspecialchars($spend, ENT_QUOTES, 'UTF-8') . '</p></div></div>'
            . '<div class="mt-4 pt-4 border-t border-default flex justify-end">'
            . $btn . '</div></div>';

        return Grid::col($inner, ['span' => $span]);
    }
}
