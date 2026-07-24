<?php

declare(strict_types=1);

namespace Converge\UI;

/**
 * Skeleton — loading placeholder generators
 *
 * Renders shimmer-animated skeleton screens for cards, tables, and lines.
 * Uses Converge design tokens CSS variables. Pure CSS animation, no JavaScript.
 *
 * Usage:
 *   echo Skeleton::card();
 *   echo Skeleton::table(4, 6);
 *   echo Skeleton::line();
 *   echo Skeleton::line(true);
 */
class Skeleton
{
    private static bool $stylesInjected = false;

    /** Card skeleton with thumbnail and text lines */
    public static function card(): string
    {
        return self::injectStyles()
            . '<div class="sk-card" aria-hidden="true">'
            . '<div class="sk-thumb"></div>'
            . '<div class="sk-line sk-line--medium"></div>'
            . '<div class="sk-line"></div>'
            . '<div class="sk-line sk-line--short"></div>'
            . '</div>';
    }

    /** Table skeleton with configurable rows and columns */
    public static function table(int $rows = 3, int $cols = 5): string
    {
        $html = self::injectStyles() . '<div class="sk-table" aria-hidden="true"><div class="sk-container">';
        for ($r = 0; $r < $rows; $r++) {
            $html .= '<div class="sk-row">';
            for ($c = 0; $c < $cols; $c++) {
                $html .= '<div class="sk-line" style="width:' . mt_rand(40, 100) . 'px;"></div>';
            }
            $html .= '</div>';
        }
        return $html . '</div></div>';
    }

    /** Single skeleton line for inline placeholders */
    public static function line(bool $short = false): string
    {
        $class = $short ? 'sk-line sk-line--short' : 'sk-line';
        return self::injectStyles() . '<div class="' . $class . '" aria-hidden="true"></div>';
    }

    /** Inject skeleton CSS once per request */
    public static function injectStyles(): string
    {
        if (self::$stylesInjected) {
            return '';
        }
        self::$stylesInjected = true;

        return '<style>'
            . '.sk-container{display:flex;flex-direction:column;gap:var(--space-4,16px);width:100%;}'
            . '.sk-row{display:flex;gap:var(--space-3,12px);align-items:center;}'
            . '.sk-line{height:14px;background:linear-gradient(90deg,var(--surface-overlay,#f1f5f9) 25%,var(--surface-raised,#fff) 50%,var(--surface-overlay,#f1f5f9) 75%);background-size:200% 100%;animation:sk-shimmer 1.5s infinite;border-radius:var(--radius-sm,8px);}'
            . '.sk-line--short{width:40%;}'
            . '.sk-line--medium{width:65%;}'
            . '.sk-card{padding:var(--space-6,24px);background:var(--surface-raised,#fff);border:1px solid var(--border-default,#e2e8f0);border-radius:var(--radius-sm,8px);}'
            . '.sk-table{padding:var(--space-4,16px);background:var(--surface-raised,#fff);border:1px solid var(--border-default,#e2e8f0);border-radius:var(--radius-sm,8px);width:100%;}'
            . '.sk-thumb{width:100%;height:160px;background:linear-gradient(90deg,var(--surface-overlay,#f1f5f9) 25%,var(--surface-raised,#fff) 50%,var(--surface-overlay,#f1f5f9) 75%);background-size:200% 100%;animation:sk-shimmer 1.5s infinite;border-radius:6px;margin-bottom:var(--space-3,12px);}'
            . '@keyframes sk-shimmer{0%{background-position:-200% 0;}100%{background-position:200% 0;}}'
            . '</style>';
    }
}
