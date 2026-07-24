<?php
declare(strict_types=1);

namespace Converge\UI\Blocks;

/**
 * FinalCta — 底部行动号召区块
 */
class FinalCta
{
    public static function render(array $props = []): string
    {
        $title    = $props['title'] ?? 'Your tracker should work harder than you do.';
        $subtitle = $props['subtitle'] ?? 'Join thousands of satisfied users.';
        $ctaText  = $props['cta_text'] ?? 'Start Free — 3 Minutes';
        $ctaUrl   = $props['cta_url'] ?? '#';
        $ctaSub   = $props['cta_sub'] ?? 'Self-hosted. Your data, your server. Free tier forever.';

        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<section class="col-span-full py-16 lg:py-24">';
        $html .= '<div class="max-w-2xl mx-auto text-center px-6">';
        $html .= '<div class="bg-gradient-to-br from-accent to-accent-emphasis rounded-3xl p-12 lg:p-16 text-content-inverse">';
        $html .= '<h2 class="text-3xl lg:text-4xl font-extrabold tracking-[-.02em] mb-4">' . $h($title) . '</h2>';
        $html .= '<p class="text-lg text-content-inverse/80 mb-8 max-w-md mx-auto">' . $h($subtitle) . '</p>';
        $html .= '<a href="' . $h($ctaUrl) . '" class="inline-flex px-8 py-4 bg-surface-raised text-accent rounded-xl text-lg font-bold no-underline shadow-lg hover:bg-surface-overlay transition">' . $h($ctaText) . '</a>';
        if ($ctaSub) $html .= '<p class="text-sm text-content-inverse/60 mt-4">' . $h($ctaSub) . '</p>';
        $html .= '</div></div></section>';
        return $html;
    }
}
