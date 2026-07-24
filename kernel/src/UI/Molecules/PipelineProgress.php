<?php

declare(strict_types=1);

namespace Converge\UI\Molecules;

/**
 * PipelineProgress — CopyPipeline 步骤进度条
 *
 * 显示 S1→S2→S3→S4→S5 的步骤进度，高亮当前步骤。
 * 用于 Builder 的 AI 生成等待反馈。
 *
 * 用法: PipelineProgress::render(['current' => 's3-jargon-replace', 'completed' => ['s1','s2']])
 */
final class PipelineProgress
{
    private const STEPS = [
        's1-one-sentence' => 'S1 提炼',
        's2-features-to-pains' => 'S2 痛点',
        's3-jargon-replace' => 'S3 翻译',
        's4-restructure' => 'S4 重组',
        's5-anchors' => 'S5 锚点',
    ];

    /**
     * @param array $state ['current' => string, 'completed' => string[]]
     */
    public static function render(array $state = []): string
    {
        $current = $state['current'] ?? '';
        $completed = $state['completed'] ?? [];

        if (empty($current) && empty($completed)) {
            return '';
        }

        $items = '';
        foreach (self::STEPS as $key => $label) {
            $cls = 'pipeline-step';
            if ($key === $current) {
                $cls .= ' active';
            } elseif (in_array($key, $completed, true)) {
                $cls .= ' done';
            }
            $items .= sprintf('<span class="%s">%s</span>', $cls, $label);
        }

        return sprintf('<div class="pipeline-progress flex gap-xs">%s</div>', $items);
    }
}
