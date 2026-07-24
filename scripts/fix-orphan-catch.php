<?php
/** Fix orphaned } catch blocks left from Latte migration */
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'public/billing.php',
    'public/campaign-stats-legacy.php',
    'public/campaign-stats.php',
    'public/campaigns.php',
    'public/click-lookup.php',
    'public/postback-urls.php',
    'public/visitors.php',
];

foreach ($files as $relPath) {
    $file = "$root/$relPath";
    $content = file_get_contents($file);
    $orig = $content;

    // Pattern: remove "} catch (Throwable $e) { ... }" block
    // and wrap LatteEngine::display in try/catch
    $content = preg_replace(
        '/\} catch \(Throwable \$e\) \{\s*\n\s*ob_end_clean\(\);\s*\n\s*error_log\("([^"]+)" \. \$e->getMessage\(\) \. " in " \. \$e->getFile\(\) \. ":" \. \$e->getLine\(\)\);\s*\n\s*\$content = \'<div class="card"[^}]*\}\s*\n\s*\}\s*\n\s*\/\/ ═══ Render via Latte ═══\s*\n\s*LatteEngine::display\(/',
        "// ═══ Render via Latte ═══\n"
        . "try {\n"
        . "    LatteEngine::display(",
        $content
    );

    // Close the try block at end of display call
    $content = preg_replace(
        '/(LatteEngine::display\([^;]+\);)\s*\n/',
        "$1\n"
        . "} catch (Throwable \$e) {\n"
        . "    error_log(\"View error: \" . \$e->getMessage());\n"
        . "    echo '<div class=\"card\" style=\"background:#ffebee;margin:var(--space-8);\">'\n"
        . "        . '<div class=\"card-body\"><h3>Error Loading Page</h3>'\n"
        . "        . '<p>' . htmlspecialchars(\$e->getMessage()) . '</p></div></div>';\n"
        . "}\n",
        $content
    );

    if ($content !== $orig) {
        file_put_contents($file, $content);
        echo "FIXED: $relPath\n";
    } else {
        echo "SKIP: $relPath (pattern not matched)\n";
    }
}
