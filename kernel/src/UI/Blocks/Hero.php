<?php
declare(strict_types=1);

namespace Converge\UI\Blocks;

/**
 * Hero — 着陆页首屏区块
 *
 * 用法:
 *   echo Hero::render(['title' => 'Grow Your Revenue', 'cta_text' => 'Start Free']);
 */
class Hero
{
    public static function render(array $props = []): string
    {
        $badge   = $props['badge'] ?? '🚀 Self-Healing Tracker';
        $title   = $props['title'] ?? 'Your tracker should work harder than you do.';
        $subtitle = $props['subtitle'] ?? 'Describe your product in one compelling sentence.';
        $ctaText = $props['cta_text'] ?? 'Start Free — 3 Minutes';
        $ctaUrl  = $props['cta_url'] ?? '#';
        $ctaSub  = $props['cta_sub'] ?? 'Self-hosted. Your data, your server. No credit card.';
        $secondaryText = $props['secondary_cta_text'] ?? 'See How It Works';
        $secondaryUrl  = $props['secondary_cta_url'] ?? '#how';

        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<section class="col-span-full py-24 lg:py-32 bg-gradient-to-b from-accent/5 via-transparent to-transparent">';
        $html .= '<div class="max-w-3xl mx-auto text-center px-6">';

        if ($badge) {
            $html .= '<span class="inline-block text-xs font-bold uppercase tracking-[.1em] text-accent mb-4">' . $h($badge) . '</span>';
        }
        $html .= '<h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold leading-[1.12] tracking-[-.03em] text-content-primary mb-6">' . $h($title) . '</h1>';
        if ($subtitle) {
            $html .= '<p class="text-lg text-content-secondary leading-relaxed mb-8 max-w-2xl mx-auto">' . $h($subtitle) . '</p>';
        }
        if ($ctaText) {
            $html .= '<a href="' . $h($ctaUrl) . '" class="inline-flex px-8 py-4 bg-accent text-content-inverse rounded-xl text-lg font-bold no-underline shadow-lg shadow-accent/25 hover:bg-accent-hover hover:-translate-y-0.5 transition-all cta-glow">👉 ' . $h($ctaText) . '</a>';
        }
        if ($secondaryText) {
            $html .= '<a href="' . $h($secondaryUrl) . '" class="inline-flex px-8 py-4 ml-3 text-accent border border-accent rounded-xl text-lg font-bold no-underline hover:bg-accent/10 hover:-translate-y-0.5 transition-all">' . $h($secondaryText) . '</a>';
        }
        if ($ctaSub) {
            $html .= '<p class="text-sm text-content-secondary mt-5">' . $h($ctaSub) . '</p>';
        }

        $html .= '</div></section>';
        return $html;
    }
}
