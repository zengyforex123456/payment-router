<?php
declare(strict_types=1);

namespace Converge\UI\Blocks;

/**
 * FormField — 表单区块
 *
 * Props:
 *   fields: [{name, label, type, placeholder, required?, options?, value?}]
 *   submitLabel: string
 *   layout: 'vertical' | 'horizontal'
 */
class FormField
{
    public static function render(array $props = []): string
    {
        $fields = $props['fields'] ?? [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'placeholder' => 'Enter name', 'required' => true],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'placeholder' => 'Enter email', 'required' => true],
            ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'options' => ['Admin', 'Editor', 'Viewer']],
            ['name' => 'bio', 'label' => 'Bio', 'type' => 'textarea', 'placeholder' => 'Tell us about yourself'],
        ];
        $submitLabel = $props['submit_label'] ?? 'Submit';
        $layout = ($props['layout'] ?? 'vertical') === 'horizontal' ? 'md:grid-cols-[200px_1fr]' : '';

        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<form class="bg-surface-raised rounded-2xl border p-6 max-w-2xl" onsubmit="return false">';
        $html .= '<div class="space-y-5">';

        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            $label = $field['label'] ?? $name;
            $type = $field['type'] ?? 'text';
            $placeholder = $field['placeholder'] ?? '';
            $required = !empty($field['required']);
            $value = $field['value'] ?? '';

            if ($layout) {
                $html .= '<div class="grid ' . $layout . ' gap-3 items-start">';
            }

            // Label
            $html .= '<label for="ff_' . $h($name) . '" class="block text-sm font-semibold text-content-primary mb-1.5">';
            $html .= $h($label);
            if ($required) $html .= ' <span class="text-danger">*</span>';
            $html .= '</label>';

            // Input wrapper
            $inputClass = 'w-full bg-surface-base rounded-lg px-3 py-2.5 text-sm border outline-none focus:border-accent transition ';

            if ($type === 'select') {
                $html .= '<select id="ff_' . $h($name) . '" name="' . $h($name) . '" class="' . $inputClass . '">';
                $html .= '<option value="">—</option>';
                foreach (($field['options'] ?? []) as $opt) {
                    $sel = ($opt === $value) ? ' selected' : '';
                    $html .= '<option value="' . $h($opt) . '"' . $sel . '>' . $h($opt) . '</option>';
                }
                $html .= '</select>';
            } elseif ($type === 'textarea') {
                $html .= '<textarea id="ff_' . $h($name) . '" name="' . $h($name) . '" placeholder="' . $h($placeholder) . '" rows="3" class="' . $inputClass . ' resize-y">' . $h($value) . '</textarea>';
            } elseif ($type === 'checkbox') {
                $checked = $value ? ' checked' : '';
                $html .= '<label class="flex items-center gap-2 text-sm text-content-secondary cursor-pointer">';
                $html .= '<input type="checkbox" id="ff_' . $h($name) . '" name="' . $h($name) . '" class="rounded accent-accent"' . $checked . '>';
                $html .= $h($placeholder ?: $label);
                $html .= '</label>';
            } else {
                // text, email, password, number, url, date
                $html .= '<input type="' . $h($type) . '" id="ff_' . $h($name) . '" name="' . $h($name) . '"';
                $html .= ' placeholder="' . $h($placeholder) . '" value="' . $h($value) . '"';
                if ($required) $html .= ' required';
                $html .= ' class="' . $inputClass . '">';
            }

            if ($layout) $html .= '</div>';
        }

        $html .= '</div>';

        // Submit button
        if ($submitLabel) {
            $html .= '<button type="submit" class="mt-6 px-6 py-3 bg-accent text-content-inverse rounded-xl text-sm font-bold hover:bg-accent-hover transition">' . $h($submitLabel) . '</button>';
        }

        $html .= '</form>';
        return $html;
    }
}
