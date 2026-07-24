<?php

declare(strict_types=1);

namespace Converge\UI\Legacy\Templates;

/**
 * BlankLayout — minimal layout with no navigation or sidebar.
 *
 * L3 template: pure content area, no chrome.
 * Suitable for: login pages, installer, error pages, iframe embeds.
 *
 * Usage:
 *   echo BlankLayout::render('<div class="text-center"><h1>404</h1><p>Not found</p></div>');
 */
class BlankLayout
{
    /**
     * @param string $content Main content HTML (pre-rendered)
     * @param array  $props   { class: string, fullHeight: bool }
     */
    public static function render(string $content, array $props = []): string
    {
        $classes = ['bg-surface-base'];

        if (!empty($props['fullHeight'])) {
            $classes[] = 'min-h-screen';
        }

        if (!empty($props['class'])) {
            $classes[] = $props['class'];
        }

        return '<div class="' . implode(' ', $classes) . '">'
            . '<main class="flex items-center justify-center p-6">'
            . $content
            . '</main>'
            . '</div>';
    }
}
