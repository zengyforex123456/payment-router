<?php
declare(strict_types=1);

namespace Converge\UI\Blocks;

/**
 * SocialProof — 客户证言/问题-解决卡片
 */
class SocialProof
{
    public static function render(array $props = []): string
    {
        $title = $props['title'] ?? 'What Our Users Say';
        $subtitle = $props['subtitle'] ?? 'Join thousands of happy customers.';
        $cards = $props['cards'] ?? [
            ['icon' => '⭐', 'problem' => '"This product changed how we work."', 'solution' => 'Add real testimonials via the cards prop.'],
            ['icon' => '🚀', 'problem' => '"We saw results in the first week."', 'solution' => 'Replace these placeholders with your content.'],
            ['icon' => '💡', 'problem' => '"The best decision we made this year."', 'solution' => 'Customize each card with icon, problem, and solution.'],
        ];

        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<section class="col-span-full py-16 lg:py-24">';
        $html .= '<div class="max-w-5xl mx-auto px-6">';
        $html .= '<div class="max-w-4xl mx-auto text-center mb-12">';
        if ($title) $html .= '<h2 class="text-3xl lg:text-4xl font-extrabold text-content-primary tracking-[-.025em] mb-4">' . $h($title) . '</h2>';
        if ($subtitle) $html .= '<p class="text-lg text-content-tertiary max-w-2xl mx-auto">' . $h($subtitle) . '</p>';
        $html .= '</div>';

        $html .= '<div class="grid grid-cols-1 md:grid-cols-3 gap-6">';
        foreach ($cards as $c) {
            $html .= '<div class="bg-surface-raised rounded-2xl p-6 border hover:border-strong transition-all duration-300 flex flex-col">';
            $html .= '<div class="text-3xl mb-3">' . $h($c['icon'] ?? '😰') . '</div>';
            $html .= '<p class="text-content-secondary leading-relaxed mb-4 text-sm">' . $h($c['problem'] ?? '') . '</p>';
            $html .= '<div class="mt-auto pt-4 border-t">';
            $html .= '<p class="text-content-primary font-semibold text-sm leading-relaxed">' . $h($c['solution'] ?? '') . '</p>';
            $html .= '</div></div>';
        }
        $html .= '</div>';

        if (!empty($props['stats'])) {
            $html .= '<div class="text-center mt-10"><p class="text-sm text-content-tertiary">' . $h($props['stats']) . '</p></div>';
        }

        $html .= '</div></section>';
        return $html;
    }
}
