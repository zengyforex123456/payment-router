<?php

declare(strict_types=1);

namespace Converge\UI\Molecules;

use Converge\UI\Badge;

/**
 * VariantTab — LP 变体标签
 *
 * 显示单个变体的切换标签，含样式标记和可选的 Badge。
 * 用于 Builder 顶部变体切换栏。
 *
 * 用法: VariantTab::render('PAS 问题驱动', true, '16/20')
 */
final class VariantTab
{
    /**
     * @param string $label 变体名称
     * @param bool $active 是否当前选中
     * @param string|null $badge 可选 Badge 文本 (如 "16/20")
     */
    public static function render(string $label, bool $active = false, ?string $badge = null): string
    {
        $badgeHtml = '';
        if ($badge !== null) {
            $badgeHtml = Badge::render($badge, ['variant' => 'info']);
        }

        $activeClass = $active ? ' active font-bold' : '';

        return sprintf(
            '<button class="btn btn-ghost%s" data-variant="%s">%s %s</button>',
            $activeClass,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            $badgeHtml,
        );
    }
}
