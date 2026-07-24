<?php

declare(strict_types=1);

namespace Converge\UI\Legacy\Molecules;

use Converge\UI\Button;
use Converge\UI\Input;

/**
 * SearchBar — 分子组件
 *
 * Composes Input + Button into a horizontal search form.
 * Zero raw form HTML in call sites.
 *
 * Usage:
 *   echo SearchBar::render([
 *       'placeholder' => 'Search campaigns...',
 *       'action'      => '/index.php?page=campaigns',
 *       'name'        => 'q',
 *       'value'       => $_GET['q'] ?? '',
 *   ]);
 *
 * Props: placeholder, value, name, action, method
 */
class SearchBar
{
    /**
     * @param array $props {
     *   placeholder: string  — input placeholder text (default: "Search...")
     *   value:       string  — current search value
     *   name:        string  — input name attribute (default: "q")
     *   action:      string  — form action URL
     *   method:      string  — get | post (default: "get")
     *   class:       string  — form element extra class
     * }
     */
    public static function render(array $props = []): string
    {
        $placeholder = $props['placeholder'] ?? 'Search...';
        $value       = $props['value'] ?? '';
        $name        = $props['name'] ?? 'q';
        $action      = $props['action'] ?? '';
        $method      = strtolower($props['method'] ?? 'get');

        $inputHtml = Input::render($name, [
            'placeholder' => $placeholder,
            'value'       => $value,
            'class'       => 'w-full',
        ]);

        $buttonHtml = Button::render('Search', ['variant' => 'primary']);

        $formClass = !empty($props['class']) ? ' class="' . $props['class'] . '"' : '';

        return '<form action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')
            . '" method="' . $method . '"'
            . $formClass . '>'
            . '<div class="flex items-center gap-2">'
            . '<div class="flex-1">' . $inputHtml . '</div>'
            . $buttonHtml
            . '</div>'
            . '</form>';
    }
}
