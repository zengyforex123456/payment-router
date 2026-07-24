<?php
declare(strict_types=1);

namespace Converge\UI\Blocks;

/**
 * TrustBar — 社会证明数据条
 */
class TrustBar
{
    public static function render(array $props = []): string
    {
        $items = $props['items'] ?? [
            ['icon' => '📊', 'value' => '34%', 'label' => 'avg CPA reduction'],
            ['icon' => '⚡', 'value' => '99.9%', 'label' => 'postback delivery rate'],
            ['icon' => '🔄', 'value' => '50ms', 'label' => 'redirect latency'],
            ['icon' => '🛡️', 'value' => '0', 'label' => 'data loss guarantee'],
        ];

        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '<section class="col-span-full py-8 bg-surface-raised border-b border">';
        $html .= '<div class="flex flex-wrap items-center justify-center gap-6 md:gap-10 text-sm text-content-secondary">';

        $first = true;
        foreach ($items as $item) {
            if (!$first) $html .= '<span class="hidden sm:inline text-content-tertiary/30">|</span>';
            $html .= '<span class="flex items-center gap-2">';
            if (!empty($item['icon'])) $html .= '<span>' . $h($item['icon']) . '</span>';
            if (!empty($item['value'])) $html .= '<span class="text-success font-bold text-lg">' . $h($item['value']) . '</span>';
            $html .= $h($item['label'] ?? '');
            $html .= '</span>';
            $first = false;
        }

        $html .= '</div></section>';
        return $html;
    }
}
