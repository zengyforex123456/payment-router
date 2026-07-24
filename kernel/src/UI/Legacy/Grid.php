<?php

declare(strict_types=1);

namespace Converge\UI\Legacy;

/**
 * Grid — 12-column layout system using Tailwind CSS atomic classes.
 *
 * Design tokens: --grid-columns:12, --grid-gap, --grid-container-max
 * All visual values come from tailwind.config.js → tokens.css CSS variables.
 *
 * Principles:
 *   - Component manages semantics, Tailwind manages styles
 *   - Zero inline style attributes
 *   - Responsive breakpoints via sm/md/lg/xl/2xl props
 *   - Gate-checked: direct grid-cols-* / col-span-* in views = violation
 *
 * Usage:
 *   echo Grid::container(
 *     Grid::row([
 *       Grid::col($sidebar, ['span' => 3]),
 *       Grid::col($main,    ['span' => 9]),
 *     ])
 *   );
 */
class Grid
{
    /** Max columns in the grid system. */
    public const MAX_COLUMNS = 12;
    /** Default gap between columns (Tailwind scale: gap-4 = 1rem = --space-4). */
    public const DEFAULT_GAP = 'gap-4';
    /** Container max-width Tailwind class. */
    public const CONTAINER_MAX = 'max-w-6xl';

    /**
     * Container — centers content with max-width and horizontal padding.
     *
     * @param string $content Inner HTML
     * @param array  $props   class, maxWidth (Tailwind max-w-*)
     */
    public static function container(string $content, array $props = []): string
    {
        $classes = [
            $props['maxWidth'] ?? self::CONTAINER_MAX,
            'mx-auto',
            'px-6',
            'w-full',
        ];
        if (isset($props['class'])) {
            $classes[] = $props['class'];
        }
        return sprintf('<div class="%s">%s</div>', implode(' ', $classes), $content);
    }

    /**
     * Row — creates a 12-column CSS Grid context.
     *
     * @param array  $columns Array of column HTML strings (from Grid::col())
     * @param array  $props   gap (Tailwind gap-*), class
     */
    public static function row(array $columns, array $props = []): string
    {
        $classes = ['grid', 'grid-cols-12'];
        $gap = $props['gap'] ?? self::DEFAULT_GAP;
        $classes[] = $gap;
        if (isset($props['class'])) {
            $classes[] = $props['class'];
        }
        $html = sprintf('<div class="%s">', implode(' ', $classes));
        foreach ($columns as $col) {
            $html .= $col;
        }
        return $html . '</div>';
    }

    /**
     * Return only the Tailwind grid column class string (no wrapping div).
     * Use when you need the class on an existing element.
     *
     * @param array $props span, sm/md/lg/xl/2xl, start, *Start, align, justify
     */
    public static function colClass(array $props = []): string
    {
        $span = isset($props['span']) ? (int)$props['span'] : self::MAX_COLUMNS;
        $span = max(1, min($span, self::MAX_COLUMNS));
        $classes = ["col-span-{$span}"];
        foreach (['sm', 'md', 'lg', 'xl', '2xl'] as $bp) {
            if (isset($props[$bp])) {
                $bpSpan = max(1, min((int)$props[$bp], self::MAX_COLUMNS));
                $classes[] = "{$bp}:col-span-{$bpSpan}";
            }
            if (isset($props["{$bp}Start"])) {
                $classes[] = "{$bp}:col-start-{$props["{$bp}Start"]}";
            }
        }
        if (isset($props['start'])) {
            $classes[] = "col-start-{$props['start']}";
        }
        if (isset($props['align'])) {
            $classes[] = "self-{$props['align']}";
        }
        if (isset($props['justify'])) {
            $classes[] = "justify-self-{$props['justify']}";
        }
        return implode(' ', $classes);
    }

    /**
     * Column — spans N columns with optional responsive overrides.
     *
     * @param string $content Inner HTML
     * @param array  $props   span (1-12), sm/md/lg/xl/2xl (responsive spans),
     *                        start, *Start, align (self-*), class
     */
    public static function col(string $content, array $props = []): string
    {
        $span = isset($props['span']) ? (int)$props['span'] : self::MAX_COLUMNS;
        $span = max(1, min($span, self::MAX_COLUMNS));

        $classes = ["col-span-{$span}"];

        // Responsive overrides
        foreach (['sm', 'md', 'lg', 'xl', '2xl'] as $bp) {
            if (isset($props[$bp])) {
                $bpSpan = max(1, min((int)$props[$bp], self::MAX_COLUMNS));
                $classes[] = "{$bp}:col-span-{$bpSpan}";
            }
        }

        // Column start (for centering: col-start-3 means start at column 3)
        if (isset($props['start'])) {
            $classes[] = "col-start-{$props['start']}";
        }
        // Responsive col-start overrides
        foreach (['sm', 'md', 'lg', 'xl', '2xl'] as $bp) {
            if (isset($props["{$bp}Start"])) {
                $classes[] = "{$bp}:col-start-{$props["{$bp}Start"]}";
            }
        }

        // Self alignment
        if (isset($props['align'])) {
            $classes[] = "self-{$props['align']}";
        }
        if (isset($props['justify'])) {
            $classes[] = "justify-self-{$props['justify']}";
        }

        if (isset($props['class'])) {
            $classes[] = $props['class'];
        }

        return sprintf('<div class="%s">%s</div>', implode(' ', $classes), $content);
    }

    /**
     * Stack — vertical stack of full-width rows (common pattern).
     * Each element gets its own row with col-span-12.
     *
     * @param array  $items Array of HTML strings
     * @param array  $props gap, class
     */
    public static function stack(array $items, array $props = []): string
    {
        $rows = array_map(
            fn(string $item): string => self::col($item, ['span' => 12]),
            $items,
        );
        return self::row($rows, $props);
    }
}
