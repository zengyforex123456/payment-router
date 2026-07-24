<?php
declare(strict_types=1);
namespace Converge\UI\Page\Wrapper;
class FullWidthWrapper {
    public static function wrap(string $content, array $page = []): string {
        $title = htmlspecialchars($page['title'] ?? 'Converge', ENT_QUOTES);
        return <<<HTML
<!DOCTYPE html><html lang="zh"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>{$title} �?Converge</title>
<link rel="stylesheet" href="/build/css/design-tokens.css?v=1784386561">
<link rel="stylesheet" href="/build/css/tailwind.min.css?v=1784386561">
<link rel="stylesheet" href="/build/css/container-queries.css?v=1784386561">
<link rel="stylesheet" href="/build/css/missing-utilities.css?v=1784386561">
</head><body class="bg-surface-base text-content-primary font-sans antialiased">
<nav class="max-w-6xl mx-auto px-6 py-3 flex items-center justify-between text-sm">
<a href="/" class="font-bold text-lg text-content-primary no-underline">Converge</a>
<a href="/builder.php" class="text-content-secondary hover:text-content-primary no-underline">🧩 Builder</a>
</nav>
<main class="w-full">{$content}</main>
<footer class="border-t mt-16 py-8 text-center text-xs text-content-tertiary">&copy; 2026 Converge</footer>
</body></html>
HTML;
    }
}
