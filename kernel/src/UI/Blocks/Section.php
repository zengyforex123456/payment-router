<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

/**
 * Section — 令牌驱动的视觉分区容器
 *
 * v2.0: 间距 → var(--space-*), 背景 → var(--surface-*)
 *
 * Props:
 *   children: string — 子区块 HTML
 *   bg: 'base'|'raised'|'overlay'|'accent'|'transparent'
 *   padding: 'sm'|'md'|'lg'|'xl'|'none'
 *   maxWidth: 'default'|'narrow'|'full'
 */
class Section
{
    private const BG_MAP = [
        'base'        => 'var(--surface-base)',
        'raised'      => 'var(--surface-raised)',
        'overlay'     => 'var(--surface-overlay)',
        'accent'      => 'var(--accent-soft, color-mix(in srgb, var(--accent) 5%, transparent))',
        'transparent' => 'transparent',
    ];

    private const PAD_MAP = [
        'none' => '0',
        'sm'   => 'var(--space-8)',
        'md'   => 'var(--space-8)',
        'lg'   => 'var(--space-8)',
        'xl'   => 'var(--space-8)',
    ];

    private const PAD_Y_MAP = [
        'none' => '0',
        'sm'   => 'var(--space-6)',
        'md'   => 'var(--space-8)',
        'lg'   => 'var(--space-8)',
        'xl'   => 'var(--space-8)',
    ];

    private const MAX_MAP = [
        'default' => '1280px',
        'narrow'  => '896px',
        'full'    => '100%',
    ];

    public static function render(array $props = []): string
    {
        $content = $props['children'] ?? $props['content'] ?? '';
        $bg = $props['bg'] ?? 'transparent';
        $padKey = $props['padding'] ?? 'md';
        $maxKey = $props['maxWidth'] ?? 'default';

        $bgVal = self::BG_MAP[$bg] ?? 'transparent';
        $padY = self::PAD_Y_MAP[$padKey] ?? 'var(--space-8)';
        $padX = self::PAD_MAP[$padKey] ?? 'var(--space-8)';
        $maxW = self::MAX_MAP[$maxKey] ?? '1280px';

        // 全 Token 驱动: 无 Tailwind 类，无硬编码 px
        $sectionStyle = "background:{$bgVal};padding-top:{$padY};padding-bottom:{$padY};";
        $innerStyle = "max-width:{$maxW};margin:0 auto;padding-left:{$padX};padding-right:{$padX};";

        $html = "<section style=\"{$sectionStyle}\">";
        $html .= "<div style=\"{$innerStyle}\">";
        $html .= $content;
        $html .= '</div>';
        $html .= '</section>';

        return $html;
    }
}
