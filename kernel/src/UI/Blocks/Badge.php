<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

/**
 * Badge — Block 包装器 (委托给 Converge\UI\Badge)
 */
class Badge
{
    public static function render(array $props = []): string
    {
        $label = $props['label'] ?? $props['text'] ?? '';
        $options = [];
        if (isset($props['variant'])) $options['variant'] = $props['variant'];
        if (isset($props['size'])) $options['size'] = $props['size'];
        return \Converge\UI\Badge::render($label, $options);
    }
}
