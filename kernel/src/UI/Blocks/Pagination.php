<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

/**
 * Pagination — 分页导航
 *
 * Props:
 *   current: int — 当前页
 *   total: int — 总页数
 *   baseUrl: string — 基础 URL（用 {page} 占位）
 *   showInfo: bool — 显示 "Page X of Y"
 */
class Pagination
{
    public static function render(array $props = []): string
    {
        $current = max(1, (int)($props['current'] ?? 1));
        $total = max(1, (int)($props['total'] ?? 5));
        $baseUrl = $props['baseUrl'] ?? '?page={page}';
        $showInfo = (bool)($props['showInfo'] ?? true);
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<nav class="flex items-center justify-between pt-4 border-t text-sm" aria-label="Pagination">';

        // Info
        if ($showInfo) {
            $html .= '<span class="text-content-tertiary">Page ' . $current . ' of ' . $total . '</span>';
        }

        // Buttons
        $html .= '<div class="flex gap-1">';
        // Prev
        $prevClass = $current <= 1 ? 'opacity-40 pointer-events-none' : 'hover:bg-surface-overlay';
        $html .= '<a href="' . $h(str_replace('{page}', (string)($current - 1), $baseUrl)) . '" class="px-3 py-1.5 rounded-lg text-content-secondary no-underline ' . $prevClass . ' transition" aria-label="Previous">&laquo;</a>';

        // Page numbers (show max 7)
        $start = max(1, $current - 3);
        $end = min($total, $current + 3);
        if ($start > 1) {
            $html .= '<a href="' . $h(str_replace('{page}', '1', $baseUrl)) . '" class="px-3 py-1.5 rounded-lg text-content-secondary no-underline hover:bg-surface-overlay transition">1</a>';
            if ($start > 2) $html .= '<span class="px-2 py-1.5 text-content-tertiary">&hellip;</span>';
        }
        for ($p = $start; $p <= $end; $p++) {
            $active = $p === $current ? 'bg-accent text-content-inverse' : 'text-content-secondary hover:bg-surface-overlay';
            $html .= '<a href="' . $h(str_replace('{page}', (string)$p, $baseUrl)) . '" class="px-3 py-1.5 rounded-lg no-underline ' . $active . ' transition">' . $p . '</a>';
        }
        if ($end < $total) {
            if ($end < $total - 1) $html .= '<span class="px-2 py-1.5 text-content-tertiary">&hellip;</span>';
            $html .= '<a href="' . $h(str_replace('{page}', (string)$total, $baseUrl)) . '" class="px-3 py-1.5 rounded-lg text-content-secondary no-underline hover:bg-surface-overlay transition">' . $total . '</a>';
        }
        // Next
        $nextClass = $current >= $total ? 'opacity-40 pointer-events-none' : 'hover:bg-surface-overlay';
        $html .= '<a href="' . $h(str_replace('{page}', (string)($current + 1), $baseUrl)) . '" class="px-3 py-1.5 rounded-lg text-content-secondary no-underline ' . $nextClass . ' transition" aria-label="Next">&raquo;</a>';
        $html .= '</div>';

        $html .= '</nav>';
        return $html;
    }
}
