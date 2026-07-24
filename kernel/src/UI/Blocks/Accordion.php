<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

/**
 * Accordion — 手风琴容器区块
 *
 * Props:
 *   items: [{title: string, content: string}]
 *   children: string — PageRenderer 渲染后的 HTML（向后兼容）
 */
class Accordion
{
    public static function render(array $props = []): string
    {
        $items = $props['items'] ?? [
            ['title' => 'Section 1', 'content' => '<p class="p-4">Content for section 1.</p>'],
            ['title' => 'Section 2', 'content' => '<p class="p-4">Content for section 2.</p>'],
        ];
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<div x-data="{ open: null }" class="accordion divide-y border rounded-xl overflow-hidden">';
        foreach ($items as $i => $item) {
            $html .= '<div class="accordion-item">';
            $html .= '<button @click="open = open === ' . $i . ' ? null : ' . $i . '" class="w-full flex items-center justify-between px-5 py-4 text-sm font-semibold text-content-primary hover:bg-surface-overlay transition text-left bg-transparent cursor-pointer" :aria-expanded="open === ' . $i . '">';
            $html .= '<span>' . $h($item['title']) . '</span>';
            $html .= '<span :class="open === ' . $i . ' ? \'rotate-180\' : \'\'" class="text-content-tertiary transition-transform text-lg">&#9660;</span>';
            $html .= '</button>';
            $html .= '<div x-show="open === ' . $i . '" x-collapse class="px-5 pb-4 text-sm text-content-secondary">' . ($item['content'] ?? '') . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}
