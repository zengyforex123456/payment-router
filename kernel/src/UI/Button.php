<?php
declare(strict_types=1);

namespace Converge\UI;

/**
 * Button — 原子组件
 *
 * 消灭所有 <button class="btn-*"> 和 <a class="btn-*"> 手写模式。
 * 组件只输出 class 名，零内联 style，样式全部在 ui-components.css 中用 var(--*) 定义。
 *
 * 用法:
 *   echo Button::render('保存', ['variant' => 'primary', 'type' => 'submit']);
 *   echo Button::render('取消', ['variant' => 'ghost', 'href' => '/back']);
 *   echo Button::render('删除', ['variant' => 'danger', 'size' => 'sm', 'onclick' => 'confirmDelete()']);
 *
 * 变体: primary | secondary | ghost | danger | link
 * 大小: sm | md | lg
 * 标签: 默认 <button>，传 href 自动切换 <a>
 */
class Button
{
    /**
     * @param string $label 按钮文本
     * @param array  $props 属性: variant, size, type, href, disabled, id, onclick, class
     */
    /**
     * @param string|array $label 按钮文本，或 props 数组（兼容 PageRenderer 调用）
     * @param array  $props 属性
     */
    public static function render(string|array $label, array $props = []): string
    {
        // 兼容 PageRenderer 调用: Button::render(['label' => '...', 'variant' => '...'])
        if (is_array($label)) {
            $props = $label;
            $label = $props['label'] ?? $props['text'] ?? 'Button';
        }
        $variant = $props['variant'] ?? 'primary';
        $size    = $props['size'] ?? 'md';
        $tag     = isset($props['href']) ? 'a' : 'button';
        $disabled = !empty($props['disabled']);

        // 构建 class 列表
        $classes = ['btn', "btn-{$variant}"];
        if ($size !== 'md') {
            $classes[] = "btn-{$size}";
        }
        if (!empty($props['class'])) {
            $classes[] = $props['class'];
        }

        $attrs = 'class="' . implode(' ', $classes) . '"';

        // type 属性（仅 <button>）
        if ($tag === 'button') {
            $type = $props['type'] ?? 'button';
            $attrs .= ' type="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '"';
        }

        // disabled
        if ($disabled) {
            $attrs .= ' disabled';
        }

        // id
        if (isset($props['id'])) {
            $attrs .= ' id="' . htmlspecialchars((string)$props['id'], ENT_QUOTES, 'UTF-8') . '"';
        }

        // onclick
        if (isset($props['onclick'])) {
            $attrs .= ' onclick="' . htmlspecialchars((string)$props['onclick'], ENT_QUOTES, 'UTF-8') . '"';
        }

        // aria-label (可访问性)
        if (isset($props['ariaLabel'])) {
            $attrs .= ' aria-label="' . htmlspecialchars((string)$props['ariaLabel'], ENT_QUOTES, 'UTF-8') . '"';
        }

        // href (仅 <a>)
        if ($tag === 'a' && isset($props['href'])) {
            $attrs .= ' href="' . htmlspecialchars((string)$props['href'], ENT_QUOTES, 'UTF-8') . '"';
        }

        // title (tooltip)
        if (isset($props['title'])) {
            $attrs .= ' title="' . htmlspecialchars((string)$props['title'], ENT_QUOTES, 'UTF-8') . '"';
        }

        return "<{$tag} {$attrs}>{$label}</{$tag}>";
    }
}
