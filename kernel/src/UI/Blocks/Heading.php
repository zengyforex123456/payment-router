<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

class Heading {
    public static function render(array $props = []): string {
        $level = min(6, max(1, (int)($props['level'] ?? 2)));
        $text = htmlspecialchars($props['text'] ?? '', ENT_QUOTES);
        $sizes = [1=>'text-4xl font-extrabold',2=>'text-3xl font-bold',3=>'text-2xl font-semibold',4=>'text-xl font-semibold',5=>'text-lg font-medium',6=>'text-base font-medium'];
        $cls = $sizes[$level] . ' tracking-tight text-content-primary';
        return "<h{$level} class=\"{$cls}\">{$text}</h{$level}>";
    }
}
