<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

/**
 * Stack — 垂直堆叠原语 (Every Layout: The Stack)
 *
 * 所有子元素间距完全一致。不对子组件注入 margin。
 * 间距由父容器统一管理。
 *
 * Props:
 *   gap: 'sm'|'md'|'lg'|'xl' — 统一间距
 *   align: 'left'|'center'|'right'|'stretch' — 水平对齐
 *   children: string — 子区块 HTML
 */
class Stack
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
        $align = $props['align'] ?? 'stretch';

        $alignMap = [
            'left'    => 'flex-start',
            'center'  => 'center',
            'right'   => 'flex-end',
            'stretch' => 'stretch',
        ];
        $alignVal = $alignMap[$align] ?? 'stretch';

        $content = $props['children'] ?? $props['content'] ?? '';

        // 核心 CSS: flex 纵向 + gap
        // 回退: owl selector (> * + *) 用于不支持 gap 的旧浏览器
        $style = "display:flex;flex-direction:column;gap:{$gap};align-items:{$alignVal};";

        return "<div style=\"{$style}\">{$content}</div>";
    }
}
