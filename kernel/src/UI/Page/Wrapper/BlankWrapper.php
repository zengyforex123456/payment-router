<?php
declare(strict_types=1);
namespace Converge\UI\Page\Wrapper;
class BlankWrapper {
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
</head><body class="bg-surface-base text-content-primary font-sans antialiased">{$content}</body></html>
HTML;
    }
}
