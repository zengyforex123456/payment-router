<?php

declare(strict_types=1);

namespace Converge\UI;

/**
 * Badge — 原子组件
 *
 * Small label for status, categories, or tags.
 * Uses design-token colors via Tailwind classes.
 *
 * Usage:
 *   echo Badge::render('Active', ['variant' => 'success']);
 *   echo Badge::render('Draft',  ['variant' => 'default', 'size' => 'sm']);
 *
 * Variants: default | success | danger | warning | info
 * Sizes: sm | md
 */
class Badge
{
    /** @var array<string, string> Variant → Tailwind color classes */
    private const VARIANT_MAP = [
        'default' => 'bg-surface-raised text-content-primary',
        'success' => 'bg-success-soft text-success',
        'danger'  => 'bg-danger-soft text-danger',
        'warning' => 'bg-warning-soft text-warning',
        'info'    => 'bg-info-soft text-info',
    ];

    /**
     * @param string $label Badge text content
     * @param array  $props {
     *   variant: string     — color scheme (default/success/danger/warning/info)
     *   size:    string     — sm | md
     *   class:   string     — additional CSS classes
     * }
     */
    public static function render(string|array $label, array $props = []): string
    {
        // PageRenderer 调用兼容: render(['label'=>'...','variant'=>'...'])
        if (is_array($label)) {
            $props = $label;
            $label = $props['label'] ?? $props['text'] ?? '';
        }
        $variant   = $props['variant'] ?? 'default';
        $size      = $props['size'] ?? 'md';
        $color     = self::VARIANT_MAP[$variant] ?? self::VARIANT_MAP['default'];
        $sizeClass = $size === 'sm' ? 'text-xs px-2 py-0.5' : 'text-sm px-3 py-1';

        $classes = ['inline-flex items-center rounded-full font-medium', $color, $sizeClass];
        if (!empty($props['class'])) {
            $classes[] = $props['class'];
        }

        return '<span class="' . implode(' ', $classes) . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
}
