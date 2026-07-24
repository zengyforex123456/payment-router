<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;
class Spacer { public static function render(array $props = []): string { $h = min(32, max(1, (int)($props['height'] ?? 4))); return "<section style=\"height:{$h}px\" aria-hidden=\"true\"></section>"; } }
