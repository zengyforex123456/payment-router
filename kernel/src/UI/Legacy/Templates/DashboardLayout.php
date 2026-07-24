<?php

declare(strict_types=1);

namespace Converge\UI\Legacy\Templates;

use Converge\UI\LatteEngine;

/**
 * DashboardLayout — app shell with sidebar + header + main content slots.
 *
 * L3 template: rendered via Latte template engine.
 * Template: templates/_layouts/dashboard.latte
 *
 * Usage:
 *   echo DashboardLayout::render([
 *       'sidebar' => SidebarNav::render($items),
 *       'header'  => '<h1>Dashboard</h1>',
 *       'main'    => StatCard::row([...]) . DataTable::render([...]),
 *       'footer'  => '© 2026 Converge',
 *   ]);
 */
class DashboardLayout
{
    /**
     * @param array $slots {
     *   sidebar: string       — left sidebar content
     *   header: string        — top bar content
     *   main: string          — main content area
     *   footer: string        — bottom bar (optional)
     *   sidebarWidth: string  — Tailwind width class (default: w-64)
     * }
     */
    public static function render(array $slots): string
    {
        return LatteEngine::render('_layouts/dashboard', [
            'sidebar'      => $slots['sidebar'] ?? '',
            'header'       => $slots['header'] ?? '',
            'main'         => $slots['main'] ?? '',
            'footer'       => $slots['footer'] ?? '',
            'sidebarWidth' => $slots['sidebarWidth'] ?? 'w-64',
        ]);
    }
}
