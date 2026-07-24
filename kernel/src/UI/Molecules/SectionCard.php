<?php

declare(strict_types=1);

namespace Converge\UI\Molecules;

use Converge\UI\Badge;

/**
 * SectionCard — LP 变体缩略卡片
 *
 * 显示一个变体的预览摘要：名称、状态 Badge、包含的 section 数量。
 * 用于 AI 生成结果列表，用户点击选择变体。
 *
 * 用法: SectionCard::render('PAS 问题驱动', ['hero','trust','how','features','cta'], true)
 */
final class SectionCard
{
    /**
     * @param string $label 变体标签
     * @param array $sections 包含的 section 键名列表
     * @param bool $active 是否当前选中
     * @param int|null $score CopyPipeline 评分 (0-20)
     */
    public static function render(
        string $label,
        array $sections = [],
        bool $active = false,
        ?int $score = null,
    ): string {
        $badge = '';
        if ($active) {
            $badge = Badge::render('当前', ['variant' => 'success']);
        }
        if ($score !== null) {
            $variant = $score >= 15 ? 'success' : ($score >= 10 ? 'warning' : 'danger');
            $badge .= Badge::render((string)$score . '/20', ['variant' => $variant]);
        }

        $sectionCount = count($sections);
        $sectionList = implode(', ', array_map(
            fn(string $s) => match ($s) {
                'hero' => 'Hero', 'trust' => 'Trust', 'how' => 'How',
                'features' => 'Features', 'comparison' => 'Compare',
                'proof' => 'Proof', 'pricing' => 'Pricing',
                'faq' => 'FAQ', 'cta' => 'CTA',
                default => ucfirst($s),
            },
            array_slice($sections, 0, 4),
        ));

        $moreHint = $sectionCount > 4 ? ' +' . ($sectionCount - 4) : '';

        return sprintf(
            '<div class="card section-card%s">'
            . '<h4>%s %s</h4>'
            . '<p class="muted text-sm">%d sections: %s%s</p>'
            . '</div>',
            $active ? ' active' : '',
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            $badge,
            $sectionCount,
            htmlspecialchars($sectionList, ENT_QUOTES, 'UTF-8'),
            $moreHint,
        );
    }
}
