<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

/**
 * Sidebar — 侧边栏布局容器
 *
 * 经典后台布局：左侧导航 + 右侧内容区。
 *
 * Props:
 *   children: string — 侧边栏子区块 HTML（PageRenderer 渲染）
 *   main: string — 主内容区 HTML
 *   sideWidth: 'sm'|'md'|'lg' — 侧边栏宽度
 *   sidePosition: 'left'|'right' — 侧边栏位置
 */
class Sidebar
{
    public static function render(array $props = []): string
    {
        $sideContent = $props['children'] ?? '';
        $mainContent = $props['main'] ?? '';
        $position = $props['sidePosition'] ?? 'left';

        $widthMap = ['sm' => 'w-48', 'md' => 'w-64', 'lg' => 'w-80'];
        $sideWidth = $widthMap[$props['sideWidth'] ?? 'md'] ?? 'w-64';

        $html = '<div class="flex gap-0 min-h-0">';

        if ($position === 'right') {
            $html .= '<main class="flex-1 min-w-0">' . $mainContent . '</main>';
            $html .= '<aside class="' . $sideWidth . ' flex-shrink-0 border-l bg-surface-raised p-4 overflow-y-auto">' . $sideContent . '</aside>';
        } else {
            $html .= '<aside class="' . $sideWidth . ' flex-shrink-0 border-r bg-surface-raised p-4 overflow-y-auto">' . $sideContent . '</aside>';
            $html .= '<main class="flex-1 min-w-0">' . $mainContent . '</main>';
        }

        $html .= '</div>';
        return $html;
    }
}
