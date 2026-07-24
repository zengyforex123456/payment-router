<?php
declare(strict_types=1);

namespace Converge\UI\Blocks;

/**
 * Faq — 常见问题区块
 */
class Faq
{
    public static function render(array $props = []): string
    {
        $badge = $props['badge'] ?? 'FAQ';
        $title = $props['title'] ?? 'Frequently Asked Questions';
        $items = $props['items'] ?? [
            ['q' => 'Do I need a server?', 'a' => 'Any PHP 8.2+ host works. $5/mo VPS runs Converge smoothly for 100K+ clicks/day. One-command Docker deploy included.'],
            ['q' => 'Can I self-host?', 'a' => 'Yes. Converge runs on your server. Your data, your rules. No vendor lock-in. Free tier ships with core features. Pro/Enterprise unlock with a license key.'],
            ['q' => 'How does self-healing work?', 'a' => 'Converge monitors postback delivery, link uptime, and server health. When it detects a failure, it auto-retries with exponential backoff, switches to backup links, and notifies you — all without manual intervention.'],
            ['q' => 'What tracking methods are supported?', 'a' => 'Pixel tracking, postback (server-to-server), and Meta CAPI / TikTok Events API. All three can run simultaneously for redundancy.'],
            ['q' => 'Is there a free plan?', 'a' => 'Yes. Free forever for up to 10 campaigns. No credit card required. All core features included.'],
        ];

        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<section class="col-span-full py-16 lg:py-24">';
        $html .= '<div class="max-w-3xl mx-auto px-6">';
        if ($badge) $html .= '<span class="inline-block text-xs font-bold uppercase tracking-[.1em] text-accent mb-3">' . $h($badge) . '</span>';
        if ($title) $html .= '<h2 class="text-3xl lg:text-4xl font-extrabold text-content-primary tracking-[-.025em] mb-10">' . $h($title) . '</h2>';

        $html .= '<div class="space-y-6">';
        foreach ($items as $item) {
            $html .= '<div>';
            $html .= '<h3 class="font-semibold text-content-primary text-base mb-2">' . $h($item['q']) . '</h3>';
            $html .= '<p class="text-sm text-content-secondary leading-relaxed">' . $h($item['a']) . '</p>';
            $html .= '</div>';
        }
        $html .= '</div>';

        $html .= '</div></section>';
        return $html;
    }
}
