<?php

declare(strict_types=1);

namespace Converge\UI\Legacy\Molecules;

/**
 * Pagination — page navigation molecule.
 *
 * Complements DataTable for list views. Generates Tailwind-styled
 * page buttons with current-page highlight and prev/next controls.
 *
 * Usage:
 *   echo Pagination::render([
 *       'current'  => 3,
 *       'total'    => 12,
 *       'baseUrl'  => 'campaigns.php?page=',
 *       'maxVisible' => 7,
 *   ]);
 */
class Pagination
{
    /**
     * @param array $props {
     *   current: int     — current page number (1-based)
     *   total: int       — total page count
     *   baseUrl: string  — URL prefix (e.g. "list.php?page=")
     *   maxVisible: int  — max page buttons shown (default 7)
     *   class: string    — extra wrapper class
     * }
     */
    public static function render(array $props): string
    {
        $current    = max(1, (int)($props['current'] ?? 1));
        $total      = max(1, (int)($props['total'] ?? 1));
        $baseUrl    = $props['baseUrl'] ?? '?page=';
        $maxVisible = (int)($props['maxVisible'] ?? 7);
        $class      = $props['class'] ?? '';

        if ($total <= 1) {
            return '';
        }

        $pages = self::buildPageRange($current, $total, $maxVisible);

        $html = '<nav class="flex items-center justify-center gap-1 py-4 ' . $class . '" aria-label="Pagination">';

        // Prev
        $prevDisabled = ($current <= 1) ? ' opacity-40 pointer-events-none' : '';
        $html .= '<a href="' . htmlspecialchars($baseUrl . ($current - 1), ENT_QUOTES, 'UTF-8') . '"'
            . ' class="px-3 py-2 rounded-lg text-sm font-medium text-content-secondary hover:bg-surface-overlay transition-colors' . $prevDisabled . '"'
            . ' aria-label="Previous page">←</a>';

        foreach ($pages as $p) {
            if ($p === '...') {
                $html .= '<span class="px-2 py-2 text-content-tertiary text-sm">…</span>';
            } elseif ($p === $current) {
                $html .= '<span class="px-3 py-2 rounded-lg text-sm font-semibold bg-accent text-content-inverse">'
                    . $p . '</span>';
            } else {
                $html .= '<a href="' . htmlspecialchars($baseUrl . $p, ENT_QUOTES, 'UTF-8') . '"'
                    . ' class="px-3 py-2 rounded-lg text-sm font-medium text-content-secondary hover:bg-surface-overlay transition-colors">'
                    . $p . '</a>';
            }
        }

        // Next
        $nextDisabled = ($current >= $total) ? ' opacity-40 pointer-events-none' : '';
        $html .= '<a href="' . htmlspecialchars($baseUrl . ($current + 1), ENT_QUOTES, 'UTF-8') . '"'
            . ' class="px-3 py-2 rounded-lg text-sm font-medium text-content-secondary hover:bg-surface-overlay transition-colors' . $nextDisabled . '"'
            . ' aria-label="Next page">→</a>';

        return $html . '</nav>';
    }

    /**
     * Build the visible page range with ellipsis.
     * e.g. [1, '...', 4, 5, 6, '...', 12]
     *
     * @return list<int|string>
     */
    private static function buildPageRange(int $current, int $total, int $maxVisible): array
    {
        if ($total <= $maxVisible) {
            return range(1, $total);
        }

        $pages = [];
        $side = (int)floor(($maxVisible - 3) / 2);

        // Always show first page
        $pages[] = 1;

        $leftStart  = max(2, $current - $side);
        $leftEnd    = min($total - 1, $current + $side);

        if ($leftStart > 2) {
            $pages[] = '...';
        }

        for ($i = $leftStart; $i <= $leftEnd; $i++) {
            $pages[] = $i;
        }

        if ($leftEnd < $total - 1) {
            $pages[] = '...';
        }

        // Always show last page
        if ($total > 1) {
            $pages[] = $total;
        }

        return $pages;
    }
}
