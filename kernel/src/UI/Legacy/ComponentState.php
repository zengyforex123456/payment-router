<?php

declare(strict_types=1);

namespace Converge\UI\Legacy;

abstract class ComponentState
{
    public const NORMAL = 'normal';
    public const LOADING = 'loading';
    public const EMPTY = 'empty';
    public const ERROR = 'error';
    /** Map state constant to a CSS class */
    public static function stateClass(string $state): string
    {
        return match ($state) {
            self::LOADING => 'state-loading',
            self::EMPTY => 'state-empty',
            self::ERROR => 'state-error',
            default => 'state-normal',
        };
    }
    /** Render a state indicator HTML snippet */
    public static function renderState(string $state, string $message = ''): string
    {
        $icon = match ($state) {
            self::LOADING => '<div class="spinner" aria-label="Loading"></div>',
            self::EMPTY => '<span class="state-icon" aria-hidden="true">&#128196;</span>',
            self::ERROR => '<span class="state-icon" aria-hidden="true">&#9888;</span>',
            default => '',
        };
        $msg = htmlspecialchars(
            $message ?: match ($state) {
                self::LOADING => 'Loading...',
                self::EMPTY => 'No data available.',
                self::ERROR => 'An error occurred.',
                default => '',
            },
            ENT_QUOTES,
            'UTF-8'
        );

        return sprintf(
            '<div class="component-state %s" role="status">%s<p class="state-message">%s</p></div>',
            self::stateClass($state),
            $icon,
            $msg
        );
    }
}
