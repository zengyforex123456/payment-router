<?php
declare(strict_types=1);

namespace Converge\UI\Blocks;

/**
 * HowItWorks — 步骤流程区块
 */
class HowItWorks
{
    public static function render(array $props = []): string
    {
        $badge = $props['badge'] ?? 'How It Works';
        $title = $props['title'] ?? 'From click to conversion in 4 steps';
        $steps = $props['steps'] ?? [
            ['title' => 'Integrate', 'desc' => 'One script tag. 2 minutes. Works with any PHP site or landing page builder.'],
            ['title' => 'Track', 'desc' => 'Every click, conversion, and postback captured with zero data loss. Real-time dashboard.'],
            ['title' => 'Optimize', 'desc' => 'Bayesian A/B testing + smart rotation auto-adjusts traffic to best-performing offers.'],
            ['title' => 'Scale', 'desc' => 'Self-healing infrastructure. Circuit breakers. Exponential retry. You sleep.'],
        ];

        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $n = count($steps);
        $lgCol = $n === 4 ? 'lg:grid-cols-4' : ($n === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-2');

        $html = '<section class="col-span-full py-16 lg:py-24">';
        $html .= '<div class="max-w-5xl mx-auto px-6">';
        if ($badge) $html .= '<span class="inline-block text-xs font-bold uppercase tracking-[.1em] text-accent mb-3">' . $h($badge) . '</span>';
        if ($title) $html .= '<h2 class="text-3xl lg:text-4xl font-extrabold text-content-primary tracking-[-.025em] mb-10">' . $h($title) . '</h2>';

        $html .= '<div class="grid grid-cols-1 sm:grid-cols-2 ' . $lgCol . ' gap-5">';
        $num = 1;
        foreach ($steps as $step) {
            $html .= '<div class="bg-surface-raised rounded-2xl p-6 text-center border hover:border-strong transition-all duration-300">';
            $html .= '<div class="inline-flex w-10 h-10 rounded-full bg-accent text-content-inverse font-extrabold text-lg items-center justify-center mb-4">' . $num . '</div>';
            $html .= '<h4 class="font-bold text-content-primary mb-2">' . $h($step['title']) . '</h4>';
            $html .= '<p class="text-sm text-content-secondary leading-relaxed">' . $h($step['desc']) . '</p>';
            $html .= '</div>';
            $num++;
        }
        $html .= '</div>';

        if (!empty($props['footer_text'])) {
            $html .= '<p class="mt-6 text-base text-content-secondary max-w-3xl">' . $h($props['footer_text']) . '</p>';
        }

        $html .= '</div></section>';
        return $html;
    }
}
