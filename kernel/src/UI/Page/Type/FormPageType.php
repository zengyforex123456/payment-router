<?php
declare(strict_types=1);

namespace Converge\UI\Page\Type;

/**
 * FormPageType — 表单页布局
 *
 * 提供标准表单骨架:
 *   [Back button]
 *   [Header: "Create X" or "Edit X"]
 *   [blockHtml — 通常是 FormField + Button]
 *   [Submit + Cancel actions]
 *
 * JSON 配置:
 *   {"pageType":"form","title":"Create User","dataSource":"demo.users","blocks":[...]}
 */
class FormPageType implements PageTypeInterface
{
    public static function type(): string { return 'form'; }

    public function wrap(string $blockHtml, array $page, array $pageData): string
    {
        $title = htmlspecialchars($page['title'] ?? 'Form', ENT_QUOTES, 'UTF-8');
        $submitLabel = htmlspecialchars($page['submitLabel'] ?? 'Save', ENT_QUOTES, 'UTF-8');
        $cancelUrl = htmlspecialchars($page['cancelUrl'] ?? '#', ENT_QUOTES, 'UTF-8');
        $method = htmlspecialchars($page['method'] ?? 'POST', ENT_QUOTES, 'UTF-8');
        $action = htmlspecialchars($page['action'] ?? '', ENT_QUOTES, 'UTF-8');

        $html = '<div class="form-page" style="max-width:720px;margin:0 auto;padding:var(--space-8) var(--space-6)">';

        // Back link
        if ($cancelUrl && $cancelUrl !== '#') {
            $html .= '<a href="' . $cancelUrl . '" class="inline-flex items-center gap-1 text-sm text-content-tertiary hover:text-content-primary no-underline mb-6 transition">Cancel</a>';
        }

        // Header
        $html .= '<h1 class="text-2xl font-extrabold text-content-primary mb-8">' . $title . '</h1>';

        // Form
        $html .= '<form method="' . $method . '"';
        if ($action) $html .= ' action="' . $action . '"';
        $html .= ' class="space-y-6">';

        // Content (FormField blocks, etc.)
        $html .= $blockHtml;

        // Actions
        $html .= '<footer class="flex items-center gap-3 pt-6 border-t">';
        $html .= '<button type="submit" class="inline-flex items-center px-6 py-3 bg-accent text-content-inverse rounded-xl text-sm font-bold hover:bg-accent-hover transition">' . $submitLabel . '</button>';
        if ($cancelUrl && $cancelUrl !== '#') {
            $html .= '<a href="' . $cancelUrl . '" class="inline-flex items-center px-6 py-3 border rounded-xl text-sm font-bold text-content-secondary hover:bg-surface-overlay no-underline transition">Cancel</a>';
        }
        $html .= '</footer>';

        $html .= '</form>';
        $html .= '</div>';
        return $html;
    }
}
