<?php

declare(strict_types=1);

namespace Converge\UI\Legacy\Organisms;

/**
 * SidebarNav — grouped navigation menu organism for dashboard sidebar slots.
 *
 * Renders groups with icons, auto-highlighting via currentPage, and collapsed
 * icon-only mode. Uses Tailwind + tokens.css design tokens. No inline style.
 *
 * Usage:
 *   echo SidebarNav::render([
 *       'items' => [['label'=>'Development','icon'=>'🚀','children'=>[
 *           ['label'=>'Builds','url'=>'index.php?page=ci-jobs','id'=>'ci-jobs'],
 *       ]]],
 *       'currentPage' => 'ci-jobs', 'collapsed' => false,
 *   ]);
 */
class SidebarNav
{
    /**
     * @param array $props { items: array, currentPage: string, collapsed: bool, class: string }
     */
    public static function render(array $props): string
    {
        $items       = $props['items'] ?? [];
        $currentPage = $props['currentPage'] ?? '';
        $collapsed   = !empty($props['collapsed']);
        $class       = $props['class'] ?? '';

        if ($items === []) {
            return '';
        }

        $html = '<nav class="flex flex-col gap-6 py-4 ' . $class . '" data-controller="sidebar-nav">';

        foreach ($items as $group) {
            $groupLabel = $group['label'] ?? '';
            $groupIcon  = $group['icon'] ?? '';
            $children   = $group['children'] ?? [];
            $groupId    = $group['id'] ?? $groupLabel;

            if ($children === []) {
                continue;
            }

            // Parent toggle button (accordion trigger)
            if (!$collapsed) {
                $html .= '<button class="w-full flex items-center gap-2 px-4 py-2 text-xs font-semibold text-content-tertiary uppercase tracking-wide bg-transparent border-0 cursor-pointer hover:text-content-secondary transition-colors"'
                    . ' data-action="click->sidebar-nav#toggle" data-sidebar-nav-group-param="' . htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') . '">'
                    . '<span class="flex-1 text-left">' . htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') . '</span>'
                    . '<span class="sidebar-chevron text-content-tertiary" data-sidebar-nav-group-param="' . htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') . '">▾</span>'
                    . '</button>';
            }

            // Children (collapsible)
            $html .= '<div data-sidebar-nav-target="group" data-sidebar-nav-group="' . htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') . '"'
                  . ' style="display:none">';

            foreach ($children as $child) {
                $childLabel = $child['label'] ?? '';
                $childUrl   = $child['url'] ?? '#';
                $childId    = $child['id'] ?? '';
                $isActive   = $child['active'] ?? ($currentPage !== '' && (
                    $childId === $currentPage
                    || str_contains($childUrl, 'page=' . $currentPage)
                ));

                $itemClasses = 'flex items-center gap-3 px-6 py-2.5 text-sm font-medium '
                    . 'transition-colors duration-150 no-underline border-l-[3px]';

                $itemClasses .= $isActive
                    ? ' bg-accent-soft text-accent border-accent font-semibold'
                    : ' border-transparent text-content-secondary hover:bg-surface-overlay hover:text-content-primary';

                $encodedUrl   = htmlspecialchars($childUrl, ENT_QUOTES, 'UTF-8');
                $encodedLabel = htmlspecialchars($childLabel, ENT_QUOTES, 'UTF-8');
                $encodedIcon  = htmlspecialchars($groupIcon, ENT_QUOTES, 'UTF-8');

                if ($collapsed) {
                    $html .= '<a href="' . $encodedUrl . '" class="' . $itemClasses . ' justify-center px-0 mx-2 rounded-lg"'
                        . ' title="' . $encodedLabel . '" aria-label="' . $encodedLabel . '">'
                        . '<span class="text-xl">' . $encodedIcon . '</span></a>';
                } else {
                    $html .= '<a href="' . $encodedUrl . '" class="' . $itemClasses . '">'
                        . '<span class="text-xl flex-shrink-0">' . $encodedIcon . '</span>'
                        . '<span>' . $encodedLabel . '</span></a>';
                }
            }

            $html .= '</div>'; // close children container
        }

        return $html . '</nav>';
    }
}
