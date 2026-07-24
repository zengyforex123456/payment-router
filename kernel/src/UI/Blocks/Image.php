<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;
class Image {
    public static function render(array $props = []): string {
        $src = htmlspecialchars($props['src'] ?? '', ENT_QUOTES);
        $alt = htmlspecialchars($props['alt'] ?? '', ENT_QUOTES);
        $rounded = ($props['rounded'] ?? true) ? 'rounded-xl' : '';
        return "<img src=\"{$src}\" alt=\"{$alt}\" class=\"w-full {$rounded}\" loading=\"lazy\">";
    }
}
