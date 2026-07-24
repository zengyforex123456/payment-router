<?php

declare(strict_types=1);

namespace Converge\UI\Legacy\Molecules;

/**
 * FormField — 分子组件
 *
 * Composes label + input + error + hint into a consistent form group.
 * Eliminates raw field-group HTML in call sites.
 *
 * Usage:
 *   echo FormField::render('Email', Input::render('email', [
 *       'type' => 'email', 'placeholder' => 'you@example.com',
 *   ]), [
 *       'error' => $error,
 *       'hint'  => 'We\'ll never share your email',
 *   ]);
 *
 * Props: error (string|null), required (bool), hint (string)
 */
class FormField
{
    /**
     * @param string $label     Field label text
     * @param string $inputHtml Pre-rendered input HTML (from Input::render or custom)
     * @param array  $props {
     *   error:    string|null  — error message (adds error styling)
     *   required: bool         — show required asterisk
     *   hint:     string       — helper text below input
     *   class:    string       — wrapper extra class
     * }
     */
    public static function render(string $label, string $inputHtml, array $props = []): string
    {
        $error    = $props['error'] ?? null;
        $required = !empty($props['required']);
        $hint     = $props['hint'] ?? '';

        $wrapperClass = 'mb-4';
        if (!empty($props['class'])) {
            $wrapperClass .= ' ' . $props['class'];
        }

        $html = '<div class="' . $wrapperClass . '">';

        // Label
        $html .= '<label class="block text-sm font-medium text-content-primary mb-1.5">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if ($required) {
            $html .= ' <span class="text-danger">*</span>';
        }
        $html .= '</label>';

        // Pre-rendered input
        $html .= $inputHtml;

        // Error message
        if ($error !== null && $error !== '') {
            $html .= '<p class="mt-1.5 text-sm text-danger">'
                . htmlspecialchars($error, ENT_QUOTES, 'UTF-8')
                . '</p>';
        }

        // Hint text
        if ($hint !== '') {
            $html .= '<p class="mt-1 text-xs text-content-tertiary">'
                . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8')
                . '</p>';
        }

        return $html . '</div>';
    }
}
