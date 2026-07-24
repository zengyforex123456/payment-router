<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

/**
 * Cluster — 自动换行水平列表 (Every Layout: The Cluster)
 *
 * 子元素水平排列，宽度不够时自动换行。
 * 适合: 标签组、按钮组、卡片列表、筛选条件。
 *
 * Props:
 *   gap: 'sm'|'md'|'lg'|'xl' — 行列间距
 *   justify: 'start'|'center'|'end'|'between' — 主轴对齐
 *   align: 'start'|'center'|'end' — 交叉轴对齐
 *   children: string — 子区块 HTML
 */
class Cluster
{
    private const GAP_MAP = [
        'sm' => 'var(--space-2, 8px)',
        'md' => 'var(--space-4, 16px)',
        'lg' => 'var(--space-6, 24px)',
        'xl' => 'var(--space-8, 32px)',
    ];

    public static function render(array $props = []): string
    {
        $gapKey = $props['gap'] ?? 'md';
        $gap = self::GAP_MAP[$gapKey] ?? 'var(--space-4, 16px)';
        $justify = $props['justify'] ?? 'start';
        $align = $props['align'] ?? 'center';

        $justifyMap = [
            'start'   => 'flex-start',
            'center'  => 'center',
            'end'     => 'flex-end',
            'between' => 'space-between',
        ];
        $justifyVal = $justifyMap[$justify] ?? 'flex-start';

        $alignMap = [
            'start'  => 'flex-start',
            'center' => 'center',
            'end'    => 'flex-end',
        ];
        $alignVal = $alignMap[$align] ?? 'center';

        $content = $props['children'] ?? $props['content'] ?? '';

        // 核心 CSS: flex-wrap + gap → 自动换行列表
        $style = "display:flex;flex-wrap:wrap;gap:{$gap};justify-content:{$justifyVal};align-items:{$alignVal};";

        return "<div style=\"{$style}\">{$content}</div>";
    }
}
