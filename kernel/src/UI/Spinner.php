<?php

declare(strict_types=1);

namespace Converge\UI;

/**
 * Spinner — loading indicator atom.
 *
 * Pure CSS animation using design tokens. No JavaScript, no images.
 * Complements Skeleton for inline loading states.
 *
 * Usage:
 *   echo Spinner::render();                     // default md, accent
 *   echo Spinner::render(['size' => 'sm']);     // small
 *   echo Spinner::render(['size' => 'lg', 'variant' => 'light']);
 */
class Spinner
{
    private const SIZES = [
        'sm' => 'w-4 h-4 border-2',
        'md' => 'w-6 h-6 border-[3px]',
        'lg' => 'w-10 h-10 border-4',
    ];

    private const VARIANTS = [
        'accent' => 'border-accent-soft border-t-accent',
        'light' => 'border-surface-overlay border-t-content-tertiary',
        'white' => 'border-white/20 border-t-white',
    ];

    public static function render(array $props = []): string
    {
        $size    = $props['size'] ?? 'md';
        $variant = $props['variant'] ?? 'accent';
        $class   = $props['class'] ?? '';

        $sizeClass    = self::SIZES[$size] ?? self::SIZES['md'];
        $variantClass = self::VARIANTS[$variant] ?? self::VARIANTS['accent'];

        return "<span class=\"inline-block rounded-full animate-spin {$sizeClass} {$variantClass} {$class}\""
            . ' role="status" aria-label="Loading"></span>';
    }

    /** Full-page centered spinner with optional message. */
    public static function page(string $message = 'Loading...'): string
    {
        $spinner = self::render(['size' => 'lg']);
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return "<div class=\"flex flex-col items-center justify-center py-16 gap-4 text-content-secondary\">"
            . $spinner
            . "<p class=\"text-sm\">{$msg}</p>"
            . '</div>';
    }
}
