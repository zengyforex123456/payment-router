<?php

declare(strict_types=1);

namespace Converge\UI\Legacy\Templates;

use Converge\UI\Grid;

/**
 * DockLayout — VS Code-style dock layout template.
 *
 * L3 template: activity bar (56px) + collapsible side panel (260px) + main content.
 * Uses Grid::container for the content area and Tailwind for sizing.
 *
 * Usage:
 *   echo DockLayout::render([
 *       'activityBar' => '<nav>...</nav>',
 *       'sidePanel'   => '<div class="p-4">...</div>',
 *       'main'        => '<h1>Dashboard</h1>',
 *   ]);
 *
 * Slots: activityBar, sidePanel, main
 */
class DockLayout
{
    /**
     * @param array $slots {
     *   activityBar: string   — leftmost icon bar content (56px width)
     *   sidePanel: string     — collapsible side panel content (260px width)
     *   main: string          — main content area
     *   showSidePanel: bool   — whether side panel is visible (default: true)
     *   sidePanelWidth: string — Tailwind width class (default: w-64)
     * }
     */
    public static function render(array $slots): string
    {
        $activityBar    = $slots['activityBar'] ?? '';
        $sidePanel      = $slots['sidePanel'] ?? '';
        $main           = $slots['main'] ?? '';
        $showSidePanel  = $slots['showSidePanel'] ?? true;
        $sideWidth      = $slots['sidePanelWidth'] ?? 'w-64';

        $html = '<div class="flex h-screen bg-surface-base overflow-hidden">';

        // Activity Bar — fixed 56px left column for icons
        $html .= '<div class="w-14 flex-shrink-0 bg-surface-raised border-r border-default '
               . 'flex flex-col items-center py-3 gap-2 overflow-y-auto">'
               . $activityBar
               . '</div>';

        // Side Panel — collapsible, fixed 260px when visible
        if ($showSidePanel && $sidePanel !== '') {
            $html .= '<aside class="' . $sideWidth . ' flex-shrink-0 bg-surface-raised '
                   . 'border-r border-default overflow-y-auto">'
                   . $sidePanel
                   . '</aside>';
        }

        // Main Content — fills remaining space
        $html .= '<div class="flex-1 flex flex-col min-w-0">'
               . Grid::container(
                   '<main class="flex-1 overflow-y-auto p-6">' . $main . '</main>',
                   ['maxWidth' => 'max-w-full'],
               )
               . '</div>'
               . '</div>';

        return $html;
    }
}
