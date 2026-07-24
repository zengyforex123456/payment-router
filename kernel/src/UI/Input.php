<?php
declare(strict_types=1);

namespace Converge\UI;

/**
 * Input — 原子组件
 *
 * 消灭所有 <div class="form-group"><label>...<input> 手写模式。
 * 自带 label + error 三态 + placeholder/value 支持。
 * 样式全部在 ui-components.css 中用 var(--*) 定义。
 *
 * 用法:
 *   echo Input::render('email', ['label' => '邮箱', 'type' => 'email', 'placeholder' => 'you@example.com']);
 *   echo Input::render('password', ['label' => '密码', 'type' => 'password', 'error' => $error]);
 *
 * 三态: default | error (传 error 字符串) | disabled (传 disabled => true)
 */
class Input
{
    /**
     * @param string $name  表单字段名
     * @param array  $props 属性: label, type, value, placeholder, error, disabled, required, autocomplete, id, class
     */
    public static function render(string $name, array $props = []): string
    {
        $label       = $props['label'] ?? '';
        $error       = $props['error'] ?? '';
        $type        = $props['type'] ?? 'text';
        $value       = htmlspecialchars((string)($props['value'] ?? ''), ENT_QUOTES, 'UTF-8');
        $placeholder = htmlspecialchars((string)($props['placeholder'] ?? ''), ENT_QUOTES, 'UTF-8');
        $disabled    = !empty($props['disabled']);
        $required    = !empty($props['required']);
        $id          = $props['id'] ?? $name;

        // class
        $classes = ['input'];
        if ($error) {
            $classes[] = 'input-error';
        }
        if (!empty($props['class'])) {
            $classes[] = $props['class'];
        }

        // attrs
        $attrs = 'class="' . implode(' ', $classes) . '"';
        $attrs .= ' type="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '"';
        $attrs .= ' name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"';
        $attrs .= ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
        $attrs .= ' placeholder="' . $placeholder . '"';
        $attrs .= ' value="' . $value . '"';

        if ($disabled) {
            $attrs .= ' disabled';
        }
        if ($required) {
            $attrs .= ' required';
        }
        if (isset($props['autocomplete'])) {
            $attrs .= ' autocomplete="' . htmlspecialchars((string)$props['autocomplete'], ENT_QUOTES, 'UTF-8') . '"';
        }

        $html = '<div class="form-group">';

        // label
        if ($label) {
            $html .= '<label class="form-label" for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">'
                  . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                  . '</label>';
        }

        // input
        $html .= '<input ' . $attrs . ' />';

        // error 消息
        if ($error) {
            $html .= '<p class="form-error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $html .= '</div>';
        return $html;
    }
}
