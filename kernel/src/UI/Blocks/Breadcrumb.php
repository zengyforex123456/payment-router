<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

/**
 * Breadcrumb — 面包屑导航
 *
 * Props:
 *   items: [{label: string, url?: string}]
 *   separator: string — 分隔符，默认 '/'
 */
class Breadcrumb
{
    public static function render(array $props = []): string
    {
        $items = $props['items'] ?? [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Users', 'url' => '/page.php?slug=users-list'],
            ['label' => 'Detail'],
        ];
        $sep = htmlspecialchars($props['separator'] ?? '/', ENT_QUOTES, 'UTF-8');
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<nav aria-label="Breadcrumb" class="flex items-center gap-2 text-sm text-content-tertiary">';
        $last = count($items) - 1;
        foreach ($items as $i => $item) {
            if ($i > 0) $html .= '<span class="text-content-tertiary/50">' . $sep . '</span>';
            if ($i === $last || empty($item['url'])) {
                $html .= '<span class="text-content-primary font-semibold">' . $h($item['label']) . '</span>';
            } else {
                $html .= '<a href="' . $h($item['url'] ?? '#') . '" class="hover:text-accent no-underline transition">' . $h($item['label']) . '</a>';
            }
        }
        $html .= '</nav>';
        return $html;
    }
}
