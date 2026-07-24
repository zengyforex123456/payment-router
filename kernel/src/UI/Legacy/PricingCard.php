<?php
declare(strict_types=1);

namespace Converge\UI\Legacy;

/**
 * PricingCard — 数据驱动组件
 * 数据模型决定UI: $plan->popular → 自动切换shadow·边框·徽章
 */
class PricingCard
{
    public static function render(array $plan): string
    {
        $name = htmlspecialchars($plan['name'] ?? '', ENT_QUOTES);
        $price = htmlspecialchars((string)($plan['price'] ?? '0'), ENT_QUOTES);
        $unit = htmlspecialchars($plan['unit'] ?? '', ENT_QUOTES);
        $desc = htmlspecialchars($plan['desc'] ?? '', ENT_QUOTES);
        $ctaText = htmlspecialchars($plan['cta_text'] ?? '', ENT_QUOTES);
        $ctaUrl = htmlspecialchars($plan['cta_url'] ?? '#', ENT_QUOTES);
        $ctaSub = htmlspecialchars($plan['cta_sub'] ?? '', ENT_QUOTES);
        $features = $plan['features'] ?? [];
        $popular = !empty($plan['popular']);

        // 数据模型 → UI状态: $popular 决定视觉
        $cardClass = $popular
            ? 'shadow-lg shadow-accent/20 relative'
            : 'shadow-sm';
        $ctaClass = $popular
            ? 'bg-accent text-content-inverse hover:bg-accent-hover'
            : 'bg-surface-raised text-content-primary hover:bg-surface-overlay shadow-sm';
        $badgeHtml = $popular
            ? '<small class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 bg-accent text-content-inverse text-xs font-bold rounded-full uppercase tracking-wider">POPULAR</small>'
            : '';

        $featuresHtml = '';
        foreach ($features as $f) {
            $f = htmlspecialchars($f, ENT_QUOTES);
            $featuresHtml .= "<li class=\"flex items-start gap-2\"><strong class=\"text-success flex-shrink-0\">✓</strong> {$f}</li>";
        }

        return <<<HTML
        <article class="rounded-2xl p-7 flex flex-col bg-surface-raised {$cardClass} hover:shadow-md transition-all duration-300 h-full">
          {$badgeHtml}
          <strong class="text-sm font-bold uppercase tracking-wider text-content-tertiary mb-1">{$name}</strong>
          <data class="text-5xl font-extrabold text-content-primary tracking-tight my-3 block" value="{$price}">
            \${$price}<small class="text-base font-normal text-content-tertiary">{$unit}</small>
          </data>
          <p class="text-sm text-content-secondary mb-5">{$desc}</p>
          <ul class="text-sm text-content-secondary flex-1 space-y-1.5 mb-6">{$featuresHtml}</ul>
          <a href="{$ctaUrl}" class="block w-full py-3 text-center rounded-xl font-semibold text-sm no-underline transition-all {$ctaClass}">
            {$ctaText}
          </a>
          {$ctaSub}
        </article>
        HTML;
    }
}
