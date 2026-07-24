<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

/**
 * Grid — 令牌驱动的容器查询网格（支持 children 嵌套）
 *
 * v2.0: 间距 → var(--space-*), 响应式 → @container, 布局策略 → span/priority
 *
 * Props:
 *   cols: int (1-12) — 默认列数
 *   gap: 'sm'|'md'|'lg'|'xl' — 间距（映射到 var(--space-*)）
 *   children: string — 子区块 HTML
 *   align: 'start'|'center'|'stretch' — 垂直对齐
 *   containerName: string — 容器名（供 @container 查询）
 */
class Grid
{
    private const GAP_MAP = [
        'sm' => 'var(--space-2, 8px)',
        'md' => 'var(--space-4, 16px)',
        'lg' => 'var(--space-6, 24px)',
        'xl' => 'var(--space-8, 32px)',
    ];

    public static function render(array $props = []): string
    {
        $cols = min(12, max(1, (int)($props['cols'] ?? 2)));
        $gapKey = $props['gap'] ?? 'md';
        $gap = self::GAP_MAP[$gapKey] ?? 'var(--space-4, 16px)';
        $align = $props['align'] ?? 'stretch';
        $containerName = $props['containerName'] ?? '';

        $content = $props['children'] ?? $props['content'] ?? '';

        $alignMap = [
            'start'   => 'start',
            'center'  => 'center',
            'stretch' => 'stretch',
        ];
        $alignVal = $alignMap[$align] ?? 'stretch';

        // 容器查询: 每个 Grid 是独立的 reference frame
        $containerAttr = $containerName ? " data-container-name=\"{$containerName}\"" : '';
        $containerClass = 'grid-container';

        // 用 CSS 变量驱动列数 + 间距（不再用 Tailwind gap-N）
        $style = "display:grid;grid-template-columns:repeat({$cols},1fr);gap:{$gap};align-items:{$alignVal};";

        // 容器查询自动响应: 宽度 < 480px → 单列
        $style .= "container-type:inline-size;";

        $html = "<section class=\"{$containerClass}\"{$containerAttr} style=\"{$style}\">";
        $html .= $content;
        $html .= '</section>';

        return $html;
    }

    public static function open(int $cols = 2, string $gapKey = 'md'): string
    {
        $gap = self::GAP_MAP[$gapKey] ?? 'var(--space-4, 16px)';
        $style = "display:grid;grid-template-columns:repeat({$cols},1fr);gap:{$gap};container-type:inline-size;";
        return "<section class=\"grid-container\" style=\"{$style}\">";
    }

    public static function close(): string
    {
        return '</section>';
    }
}
