<?php

declare(strict_types=1);

namespace Converge\UI;

/** Catch render exceptions and show degraded UI using token CSS variables */
class ErrorBoundary
{
    private static bool $stylesInjected = false;

    /** Wrap render callable, returning fallback UI on exception */
    public static function wrap(callable $renderFn, string $componentName = ''): string
    {
        try {
            return $renderFn();
        } catch (\Throwable $e) {
            return self::onError($e, $componentName);
        }
    }

    /** Quick-check if a render callable succeeds */
    public static function canRender(callable $checkFn): bool
    {
        try {
            $checkFn();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Log error and return fallback UI */
    private static function onError(\Throwable $e, string $componentName): string
    {
        error_log(sprintf(
            '[Converge] %s render failed: %s in %s:%d',
            $componentName ?: 'Component',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        $name = htmlspecialchars($componentName ?: 'Section', ENT_QUOTES, 'UTF-8');

        return self::injectStyles()
             . '<div class="eb-fallback" role="alert">'
             . '<div class="eb-icon" aria-hidden="true">&#9888;</div>'
             . '<div class="eb-title">Something went wrong</div>'
             . '<div class="eb-desc">Failed to load ' . $name . '. Please try again.</div>'
             . '<button class="eb-retry" onclick="location.reload()" type="button">Refresh Page</button>'
             . '</div>';
    }

    /** Inject fallback CSS once per request */
    public static function injectStyles(): string
    {
        if (self::$stylesInjected) {
            return '';
        }
        self::$stylesInjected = true;

        return '<style>'
            . '.eb-fallback{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:var(--space-10,40px) var(--space-5,20px);text-align:center;border:1px solid var(--border-default,#e2e8f0);border-radius:var(--radius-sm,8px);background:var(--surface-raised,#fff);min-height:120px;}'
            . '.eb-icon{font-size:32px;margin-bottom:var(--space-3,12px);opacity:.6;}'
            . '.eb-title{font-size:var(--text-lg,18px);font-weight:var(--weight-semibold,600);color:var(--content-primary,#0f172a);margin-bottom:var(--space-2,8px);}'
            . '.eb-desc{font-size:var(--text-sm,14px);color:var(--content-secondary,#475569);margin-bottom:var(--space-4,16px);line-height:1.5;}'
            . '.eb-retry{padding:var(--space-2,8px) var(--space-5,20px);background:var(--accent-emphasis,#2563eb);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:var(--text-sm,14px);transition:opacity var(--transition,150ms);}'
            . '.eb-retry:hover{opacity:.85;}'
            . '.eb-retry:focus-visible{outline:2px solid var(--accent-emphasis,#2563eb);outline-offset:2px;}'
            . '</style>';
    }
}
