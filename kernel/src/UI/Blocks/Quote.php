<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;
class Quote {
    public static function render(array $props = []): string {
        $text = htmlspecialchars($props['text'] ?? '', ENT_QUOTES);
        $author = htmlspecialchars($props['author'] ?? '', ENT_QUOTES);
        $authorHtml = $author ? "<footer class=\"text-sm text-content-tertiary mt-3\">— {$author}</footer>" : '';
        return "<blockquote class=\"border-l-4 border-accent pl-5 py-2 text-content-secondary italic\">{$text}{$authorHtml}</blockquote>";
    }
}
