<?php
declare(strict_types=1);

namespace Converge\UI\Blocks;

/**
 * Comparison — 竞品对比表
 */
class Comparison
{
    public static function render(array $props = []): string
    {
        $badge = $props['badge'] ?? 'Comparison';
        $title = $props['title'] ?? 'See how we compare';
        $competitors = $props['competitors'] ?? ['Voluum', 'RedTrack', 'Binom'];
        $rows = $props['rows'] ?? [
            ['label' => 'Self-Healing', 'converge' => '✅ Auto', 'values' => ['❌', '❌', '❌'], 'highlight' => true],
            ['label' => 'Bayesian A/B', 'converge' => '✅ 10K Sims', 'values' => ['⚠️ Basic', '⚠️ Basic', '❌']],
            ['label' => 'Self-Hosted', 'converge' => '✅ You own it', 'values' => ['❌', '❌', '❌']],
            ['label' => 'CAPI Native', 'converge' => '✅ Free', 'values' => ['💲 Add-on', '💲 Add-on', '❌']],
            ['label' => 'Circuit Breaker', 'converge' => '✅ Built-in', 'values' => ['❌', '❌', '❌']],
        ];

        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<section class="col-span-full py-16 lg:py-24 bg-surface-raised">';
        $html .= '<div class="max-w-4xl mx-auto px-6">';
        if ($badge) $html .= '<span class="inline-block text-xs font-bold uppercase tracking-[.1em] text-accent mb-3">' . $h($badge) . '</span>';
        if ($title) $html .= '<h2 class="text-3xl lg:text-4xl font-extrabold text-content-primary tracking-[-.025em] mb-8">' . $h($title) . '</h2>';

        // Desktop table
        $html .= '<div class="hidden md:block overflow-hidden rounded-2xl border bg-surface-raised shadow-sm">';
        $html .= '<table class="w-full border-collapse text-sm"><thead><tr class="bg-surface-overlay">';
        $html .= '<th class="py-3.5 px-5 text-left text-xs font-bold uppercase tracking-[.05em] text-content-primary"></th>';
        $html .= '<th class="py-3.5 px-5 text-center text-xs font-bold uppercase tracking-[.05em] text-accent bg-accent/5">Converge</th>';
        foreach ($competitors as $c) {
            $html .= '<th class="py-3.5 px-5 text-center text-xs font-bold uppercase tracking-[.05em] text-content-tertiary">' . $h($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $hl = !empty($row['highlight']);
            $html .= '<tr class="border-t ' . ($hl ? 'bg-success-soft' : '') . '">';
            $html .= '<td class="py-3.5 px-5 font-semibold text-content-primary ' . ($hl ? 'border-l-[3px] border-l-success' : '') . '">' . $h($row['label']) . '</td>';
            $html .= '<td class="py-3.5 px-5 text-center font-bold ' . ($hl ? 'text-success' : 'text-accent') . ' bg-accent/5">' . $h($row['converge'] ?? '✅') . '</td>';
            foreach (($row['values'] ?? []) as $v) {
                $html .= '<td class="py-3.5 px-5 text-center text-content-tertiary">' . $h($v) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        // Mobile cards
        $html .= '<div class="md:hidden space-y-4">';
        foreach ($rows as $row) {
            $hl = !empty($row['highlight']);
            $html .= '<div class="' . ($hl ? 'bg-success-soft border-success/20' : 'bg-surface-raised') . ' rounded-xl p-5 border">';
            $html .= '<p class="font-semibold text-content-primary mb-2">' . $h($row['label']) . '</p>';
            $html .= '<div class="flex flex-wrap gap-3 text-xs">';
            $html .= '<span class="px-2 py-0.5 bg-accent/5 text-accent font-semibold rounded">Converge: ' . $h($row['converge'] ?? '✅') . '</span>';
            $i = 0;
            foreach (($row['values'] ?? []) as $v) {
                $html .= '<span class="px-2 py-0.5 text-content-tertiary">' . $h($competitors[$i] ?? '?') . ': ' . $h($v) . '</span>';
                $i++;
            }
            $html .= '</div></div>';
        }
        $html .= '</div>';

        if (!empty($props['footer_note'])) {
            $html .= '<p class="text-xs text-content-tertiary mt-4">' . $h($props['footer_note']) . '</p>';
        }

        $html .= '</div></section>';
        return $html;
    }
}
