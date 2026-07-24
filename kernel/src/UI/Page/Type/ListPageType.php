<?php
declare(strict_types=1);

namespace Converge\UI\Page\Type;

/**
 * ListPageType — 列表页布局
 *
 * 提供标准 CRUD 列表骨架:
 *   [Header: title + "Create" action]
 *   [Search + Filter bar]
 *   [blockHtml — 通常是 Table]
 *   [Pagination info]
 *
 * JSON 配置:
 *   {"pageType":"list","title":"Users","dataSource":"demo.users","blocks":[...]}
 */
class ListPageType implements PageTypeInterface
{
    public static function type(): string { return 'list'; }

    public function wrap(string $blockHtml, array $page, array $pageData): string
    {
        $title = htmlspecialchars($page['title'] ?? 'List', ENT_QUOTES, 'UTF-8');
        $createLabel = htmlspecialchars($page['createLabel'] ?? '+ New', ENT_QUOTES, 'UTF-8');
        $createUrl = htmlspecialchars($page['createUrl'] ?? '#', ENT_QUOTES, 'UTF-8');
        $total = count($pageData);
        $searchPlaceholder = htmlspecialchars($page['searchPlaceholder'] ?? 'Search...', ENT_QUOTES, 'UTF-8');

        $html = '<div class="list-page" style="max-width:1280px;margin:0 auto;padding:var(--space-8) var(--space-6)">';

        // Header: title + action
        $html .= '<header class="flex items-center justify-between mb-6">';
        $html .= '<div>';
        $html .= '<h1 class="text-2xl font-extrabold text-content-primary">' . $title . '</h1>';
        if ($total > 0) {
            $html .= '<p class="text-sm text-content-tertiary mt-1">' . $total . ' records</p>';
        }
        $html .= '</div>';
        if ($createUrl && $createUrl !== '#') {
            $html .= '<a href="' . $createUrl . '" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-accent text-content-inverse rounded-xl text-sm font-bold no-underline hover:bg-accent-hover transition">+' . $createLabel . '</a>';
        }
        $html .= '</header>';

        // Search bar
        $html .= '<div class="mb-4">';
        $html .= '<input type="search" placeholder="' . $searchPlaceholder . '" class="w-full max-w-sm bg-surface-raised border rounded-lg px-4 py-2.5 text-sm outline-none focus:border-accent transition"';
        if (!empty($page['searchField'])) {
            $html .= ' data-search-field="' . htmlspecialchars($page['searchField'], ENT_QUOTES, 'UTF-8') . '"';
        }
        $html .= '>';
        $html .= '</div>';

        // Content
        $html .= '<div class="list-content">' . $blockHtml . '</div>';

        // Pagination
        $html .= '<footer class="flex items-center justify-between mt-4 pt-4 border-t text-sm text-content-tertiary">';
        $html .= '<span>Showing ' . $total . ' records</span>';
        $html .= '<nav class="flex gap-1">';
        $html .= '<span class="px-3 py-1.5 rounded-lg bg-accent text-content-inverse text-xs font-bold">1</span>';
        $html .= '</nav>';
        $html .= '</footer>';

        $html .= '</div>';
        return $html;
    }
}
