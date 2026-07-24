<?php
declare(strict_types=1);

namespace Converge\UI\Blocks;

/**
 * FeatureGrid — 能力卡片网格
 */
class FeatureGrid
{
    public static function render(array $props = []): string
    {
        $badge = $props['badge'] ?? 'Features';
        $title = $props['title'] ?? 'Built for performance. Backed by autonomy.';
        $subtitle = $props['subtitle'] ?? 'Every capability engineered to run without you.';
        $features = $props['features'] ?? [
            ['icon' => '⚡', 'title' => 'Feature One', 'desc' => 'Describe your first key feature here. What makes your product unique?'],
            ['icon' => '🔒', 'title' => 'Feature Two', 'desc' => 'Describe your second key feature. Focus on the benefit to the user.'],
            ['icon' => '🔄', 'title' => 'Smart Rotation', 'desc' => 'Traffic auto-adjusts by EPC. Best offers get more clicks. Losers paused.'],
            ['icon' => '📤', 'title' => 'Zero-Loss Postbacks', 'desc' => 'Dead letter queue + exponential retry + circuit breaker. 0 conversions lost.'],
            ['icon' => '🔓', 'title' => 'Self-Hosted', 'desc' => 'PHP 8.2 + MySQL 8. Deploy on your server. Your data, your rules. Code auditable.'],
            ['icon' => '🌐', 'title' => 'CAPI Native', 'desc' => 'Meta + TikTok + Google. 15-parameter user_data. Real-time refund signals.'],
        ];

        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $cols = (int)($props['cols'] ?? 3);
        $lgCol = $cols === 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3';

        $html = '<section class="col-span-full py-16 lg:py-24">';
        $html .= '<div class="max-w-5xl mx-auto px-6">';
        if ($badge) $html .= '<span class="inline-block text-xs font-bold uppercase tracking-[.1em] text-accent mb-3">' . $h($badge) . '</span>';
        if ($title) $html .= '<h2 class="text-3xl lg:text-4xl font-extrabold text-content-primary tracking-[-.025em] mb-4">' . $h($title) . '</h2>';
        if ($subtitle) $html .= '<p class="text-base text-content-secondary leading-relaxed mb-8 max-w-2xl">' . $h($subtitle) . '</p>';

        $html .= '<div class="grid grid-cols-1 sm:grid-cols-2 ' . $lgCol . ' gap-5">';
        foreach ($features as $feat) {
            $html .= '<div class="bg-surface-raised rounded-2xl p-6 border hover:border-strong hover:-translate-y-0.5 transition-all duration-300">';
            $html .= '<div class="text-2xl mb-3">' . $h($feat['icon'] ?? '') . '</div>';
            $html .= '<h4 class="font-bold text-content-primary text-lg mb-2">' . $h($feat['title'] ?? '') . '</h4>';
            $html .= '<p class="text-sm text-content-secondary leading-relaxed">' . $h($feat['desc'] ?? '') . '</p>';
            $html .= '</div>';
        }
        $html .= '</div>';

        if (!empty($props['footer_note'])) {
            $html .= '<p class="text-xs text-content-tertiary mt-6">' . $h($props['footer_note']) . '</p>';
        }

        $html .= '</div></section>';
        return $html;
    }
}
