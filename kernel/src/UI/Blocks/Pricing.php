<?php
declare(strict_types=1);

namespace Converge\UI\Blocks;

/**
 * Pricing — 定价卡片区块
 */
class Pricing
{
    public static function render(array $props = []): string
    {
        $badge = $props['badge'] ?? 'Pricing';
        $title = $props['title'] ?? 'Simple, transparent pricing';
        $plans = $props['plans'] ?? [
            ['name' => 'Free', 'price' => '0', 'unit' => '/mo', 'desc' => 'For solo affiliates getting started.', 'features' => ['Up to 10 campaigns', '1 team member', 'Basic analytics', 'Community support'], 'cta_text' => 'Start Free', 'cta_url' => '#'],
            ['name' => 'Pro', 'price' => '49', 'unit' => '/mo', 'desc' => 'For growing affiliates who need automation.', 'features' => ['Unlimited campaigns', '5 team members', 'Bayesian A/B testing', 'Smart rotation', 'CAPI integration', 'Priority support'], 'popular' => true, 'cta_text' => 'Start Pro', 'cta_url' => '#'],
            ['name' => 'Enterprise', 'price' => '149', 'unit' => '/mo', 'desc' => 'For networks and agencies at scale.', 'features' => ['Everything in Pro', 'Unlimited team members', 'White-label dashboard', 'Dedicated support', 'Custom integrations', 'SLA guarantee'], 'cta_text' => 'Contact Us', 'cta_url' => '#'],
        ];

        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<section class="col-span-full py-16 lg:py-24" id="pricing">';
        $html .= '<div class="max-w-5xl mx-auto px-6 text-center">';
        if ($badge) $html .= '<span class="inline-block text-xs font-bold uppercase tracking-[.1em] text-accent mb-3">' . $h($badge) . '</span>';
        if ($title) $html .= '<h2 class="text-3xl lg:text-4xl font-extrabold text-content-primary tracking-[-.025em] mb-10">' . $h($title) . '</h2>';

        $html .= '<div class="grid grid-cols-1 md:grid-cols-3 gap-5 max-w-4xl mx-auto">';
        foreach ($plans as $plan) {
            $popular = !empty($plan['popular']);
            $html .= '<div class="rounded-2xl p-7 flex flex-col bg-surface-raised border hover:border-strong transition-all duration-300' . ($popular ? ' border-accent shadow-lg shadow-accent/10 relative' : '') . '">';
            if ($popular) {
                $html .= '<div class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 bg-accent text-content-inverse text-[11px] font-bold rounded-full uppercase tracking-wider">POPULAR</div>';
            }
            $html .= '<div class="text-sm font-bold uppercase tracking-[.05em] text-content-tertiary mb-1">' . $h($plan['name']) . '</div>';
            $html .= '<div class="text-5xl font-extrabold text-content-primary tracking-[-.02em] my-3">$' . $h($plan['price']) . '<small class="text-base font-normal text-content-tertiary">' . $h($plan['unit'] ?? '') . '</small></div>';
            if (!empty($plan['desc'])) $html .= '<p class="text-sm text-content-secondary mb-5">' . $h($plan['desc']) . '</p>';
            if (!empty($plan['features'])) {
                $html .= '<ul class="text-sm text-content-secondary flex-1 space-y-1.5 mb-6 text-left" style="list-style:none">';
                foreach ($plan['features'] as $feat) {
                    $html .= '<li class="flex items-start gap-2"><span class="text-success font-bold flex-shrink-0">✓</span> ' . $h($feat) . '</li>';
                }
                $html .= '</ul>';
            }
            if (!empty($plan['cta_text'])) {
                $btnClass = $popular ? 'bg-accent text-content-inverse hover:bg-accent-hover' : 'bg-surface-raised border text-content-primary hover:bg-surface-overlay';
                $html .= '<a href="' . $h($plan['cta_url'] ?? '#') . '" class="block w-full py-3 text-center rounded-xl font-semibold text-sm no-underline transition-all ' . $btnClass . ' shadow-sm">' . $h($plan['cta_text']) . '</a>';
            }
            if (!empty($plan['cta_sub'])) $html .= '<p class="text-[11px] text-content-tertiary mt-3 text-center">' . $h($plan['cta_sub']) . '</p>';
            $html .= '</div>';
        }
        $html .= '</div>';

        $html .= '</div></section>';
        return $html;
    }
}
