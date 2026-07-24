<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;
class Alert {
    public static function render(array $props = []): string {
        $variant = $props['variant'] ?? 'info';
        $text = htmlspecialchars($props['text'] ?? '', ENT_QUOTES);
        $icons = ['info'=>'ℹ️','success'=>'✅','warning'=>'⚠️','danger'=>'🚫'];
        $colors = ['info'=>'bg-accent-soft border-accent/20 text-accent','success'=>'bg-success-soft border-success/20 text-success','warning'=>'bg-warning-soft border-warning/20 text-warning','danger'=>'bg-danger-soft border-danger/20 text-danger'];
        $icon = $icons[$variant] ?? 'ℹ️';
        $cls = $colors[$variant] ?? $colors['info'];
        return "<section class=\"rounded-xl p-4 border {$cls} flex items-start gap-3 text-sm\"><strong>{$icon}</strong><p>{$text}</p></section>";
    }
}
