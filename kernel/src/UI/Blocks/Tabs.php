<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

/**
 * Tabs — 标签页容器区块
 *
 * Props:
 *   tabLabels: [string] — 标签名称数组
 *   children: string — 每个 tab 的内容（PageRenderer 渲染后为 HTML）
 *   active: int — 默认激活的 tab (0-based)
 */
class Tabs
{
    public static function render(array $props = []): string
    {
        $labels = $props['tabLabels'] ?? ['Tab 1', 'Tab 2'];
        $content = $props['children'] ?? '';
        $active = (int)($props['active'] ?? 0);
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<div x-data="{ activeTab: ' . $active . ' }" class="tabs-container">';
        // Nav
        $html .= '<nav class="flex border-b" role="tablist">';
        foreach ($labels as $i => $label) {
            $html .= '<button @click="activeTab=' . $i . '" :class="activeTab===' . $i . ' ? \'border-accent text-accent\' : \'border-transparent text-content-tertiary hover:text-content-primary\'" class="px-5 py-3 -mb-px text-sm font-semibold border-b-2 bg-transparent cursor-pointer transition" role="tab">' . $h($label) . '</button>';
        }
        $html .= '</nav>';
        // Panels
        $html .= '<div class="py-4">' . $content . '</div>';
        $html .= '</div>';
        return $html;
    }
}
