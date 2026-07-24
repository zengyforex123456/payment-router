<?php
declare(strict_types=1);

namespace Converge\UI\Page\Type;

/**
 * DetailPageType — 详情页布局
 *
 * 提供标准详情视图骨架:
 *   [Back button]
 *   [Header: title + status badge + action buttons (Edit/Delete)]
 *   [blockHtml — Cards, Tables, etc.]
 *
 * JSON 配置:
 *   {"pageType":"detail","title":"User Detail","dataSource":"demo.users","blocks":[...]}
 */
class DetailPageType implements PageTypeInterface
{
    public static function type(): string { return 'detail'; }

    public function wrap(string $blockHtml, array $page, array $pageData): string
    {
        $title = htmlspecialchars($page['title'] ?? 'Detail', ENT_QUOTES, 'UTF-8');
        $backLabel = htmlspecialchars($page['backLabel'] ?? 'Back', ENT_QUOTES, 'UTF-8');
        $backUrl = htmlspecialchars($page['backUrl'] ?? '#', ENT_QUOTES, 'UTF-8');
        $editUrl = htmlspecialchars($page['editUrl'] ?? '', ENT_QUOTES, 'UTF-8');
        $deleteUrl = htmlspecialchars($page['deleteUrl'] ?? '', ENT_QUOTES, 'UTF-8');

        // Status badge from first row
        $status = '';
        if (!empty($pageData[0]['status'])) {
            $s = htmlspecialchars((string)$pageData[0]['status'], ENT_QUOTES, 'UTF-8');
            $color = match(strtolower($s)) {
                'active', 'completed' => 'bg-success-soft text-success',
                'pending' => 'bg-warning-soft text-warning',
                'failed', 'inactive' => 'bg-danger-soft text-danger',
                default => 'bg-surface-overlay text-content-secondary',
            };
            $status = '<span class="inline-block px-3 py-1 rounded-full text-xs font-bold ' . $color . '">' . $s . '</span>';
        }

        $html = '<div class="detail-page" style="max-width:960px;margin:0 auto;padding:var(--space-8) var(--space-6)">';

        // Back button
        if ($backUrl && $backUrl !== '#') {
            $html .= '<a href="' . $backUrl . '" class="inline-flex items-center gap-1 text-sm text-content-tertiary hover:text-content-primary no-underline mb-4 transition">' . $backLabel . '</a>';
        }

        // Header
        $html .= '<header class="flex items-start justify-between mb-8">';
        $html .= '<div class="flex items-center gap-3">';
        $html .= '<h1 class="text-2xl font-extrabold text-content-primary">' . $title . '</h1>';
        $html .= $status;
        $html .= '</div>';

        // Action buttons
        $html .= '<div class="flex gap-2">';
        if ($editUrl) {
            $html .= '<a href="' . $editUrl . '" class="inline-flex items-center px-4 py-2 bg-accent text-content-inverse rounded-xl text-sm font-bold no-underline hover:bg-accent-hover transition">Edit</a>';
        }
        if ($deleteUrl) {
            $html .= '<a href="' . $deleteUrl . '" class="inline-flex items-center px-4 py-2 border border-danger text-danger rounded-xl text-sm font-bold no-underline hover:bg-danger/10 transition" onclick="return confirm(\'Delete?\')">Delete</a>';
        }
        $html .= '</div>';
        $html .= '</header>';

        // Content
        $html .= '<div class="detail-content space-y-6">' . $blockHtml . '</div>';

        $html .= '</div>';
        return $html;
    }
}
