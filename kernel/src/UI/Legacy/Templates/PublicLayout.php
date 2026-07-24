<?php

declare(strict_types=1);

namespace Converge\UI\Legacy\Templates;

use Converge\UI\LatteEngine;

/**
 * PublicLayout — centered header + main + footer for landing/marketing pages.
 *
 * L3 template: rendered via Latte template engine.
 * Template: templates/_layouts/public.latte
 *
 * Usage:
 *   echo PublicLayout::render([
 *       'header' => '<nav>...</nav>',
 *       'main'   => $heroComponent . $featuresComponent,
 *       'footer' => '<p>© 2026 Converge</p>',
 *   ]);
 */
class PublicLayout
{
    /**
     * @param array $slots {
     *   header: string   — top navigation
     *   main: string     — page content
     *   footer: string   — bottom section
     *   maxWidth: string — Tailwind max-w-* (default: max-w-6xl)
     * }
     */
    public static function render(array $slots): string
    {
        return LatteEngine::render('_layouts/public', [
            'header'   => $slots['header'] ?? '',
            'main'     => $slots['main'] ?? '',
            'footer'   => $slots['footer'] ?? '',
            'maxWidth' => $slots['maxWidth'] ?? 'max-w-6xl',
        ]);
    }
}
