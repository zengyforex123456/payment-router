<?php

declare(strict_types=1);

namespace Converge\UI\Legacy\Molecules;

/**
 * EmptyState — zero-data placeholder molecule.
 *
 * Completes the 4-state rendering pattern:
 *   normal → your content
 *   loading → Skeleton or Spinner
 *   empty → EmptyState (this)
 *   error → ErrorBoundary fallback
 *
 * Usage:
 *   echo EmptyState::render([
 *       'icon' => '📋',
 *       'title' => 'No campaigns yet',
 *       'description' => 'Create your first campaign to get started.',
 *       'action' => ['label' => 'Create Campaign', 'url' => 'campaign-create.php'],
 *   ]);
 */
class EmptyState
{
    /**
     * @param array $props {
     *   icon: string       — emoji or icon HTML (default: 📭)
     *   title: string      — primary message
     *   description: string— secondary message
     *   action: array      — ['label'=>'...', 'url'=>'...'] optional CTA button
     *   class: string      — extra wrapper class
     * }
     */
    public static function render(array $props): string
    {
        $icon        = $props['icon'] ?? '📭';
        $title       = $props['title'] ?? 'No data';
        $description = $props['description'] ?? '';
        $action      = $props['action'] ?? null;
        $class       = $props['class'] ?? '';

        $html = '<div class="flex flex-col items-center justify-center py-16 px-4 text-center ' . $class . '">'
            . '<div class="text-5xl mb-4 opacity-60">' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<h3 class="text-lg font-semibold text-content-primary mb-2">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</h3>';

        if ($description !== '') {
            $html .= '<p class="text-sm text-content-secondary max-w-md mb-6">'
                . htmlspecialchars($description, ENT_QUOTES, 'UTF-8')
                . '</p>';
        }

        if ($action !== null && isset($action['label'])) {
            $url = $action['url'] ?? '#';
            $label = htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8');
            $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
                . ' class="inline-flex px-5 py-2.5 bg-accent text-content-inverse rounded-lg text-sm font-semibold no-underline hover:bg-accent-hover transition-colors">'
                . $label . '</a>';
        }

        return $html . '</div>';
    }
}
