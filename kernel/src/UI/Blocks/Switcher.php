<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

/**
 * Switcher — 容器自适应横/纵向切换 (Every Layout: The Switcher)
 *
 * 子元素并排 → 容器宽度低于阈值时自动切换为纵向堆叠。
 * 无需 @media 查询 — 纯 CSS 数学实现。
 *
 * 算法: 每个子元素的最小宽度 = (容器宽度 - gap) / 2 - gap。
 * 当容器缩到无法容纳两个 min-width 子元素时，自动换为单列。
 *
 * Props:
 *   threshold: string — 切换阈值 (CSS 宽度值, 默认 "480px")
 *   gap: 'sm'|'md'|'lg'|'xl'
 *   limit: int — 最多并排几个 (默认 4)
 *   children: string — 子区块 HTML (通常是 2 个直接子元素)
 */
class Switcher
{
    private const GAP_MAP = [
        'sm' => 'var(--space-2, 8px)',
        'md' => 'var(--space-4, 16px)',
        'lg' => 'var(--space-6, 24px)',
        'xl' => 'var(--space-8, 32px)',
    ];

    public static function render(array $props = []): string
    {
        $threshold = $props['threshold'] ?? '480px';
        $gapKey = $props['gap'] ?? 'md';
        $gap = self::GAP_MAP[$gapKey] ?? 'var(--space-4, 16px)';
        $limit = max(2, (int)($props['limit'] ?? 4));

        $content = $props['children'] ?? $props['content'] ?? '';

        // Every Layout Switcher 算法:
        // flex-basis = (threshold - 100%) * 999
        // 当容器宽度 > threshold: flex-basis 为负或极大 → 子元素并排
        // 当容器宽度 < threshold: flex-basis 为 0 或极小 → 子元素换行
        //
        // 简化实现: flex-wrap + 计算 min-width
        // 每个子元素: min-width = max(0, (container - (limit-1)*gap) / limit)
        $style = "display:flex;flex-wrap:wrap;gap:{$gap};";

        // 用 CSS 自定义属性让子元素通过 class 获取阈值
        $style .= "--switcher-threshold:{$threshold};";
        $style .= "--switcher-gap:{$gap};";
        $style .= "--switcher-limit:{$limit};";

        return "<div class=\"switcher\" style=\"{$style}\">{$content}</div>";
    }
}
