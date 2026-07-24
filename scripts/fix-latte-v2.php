<?php
/**
 * fix-latte-v2.php — Robust char-by-char conversion of {% %} → { }
 *
 * Unlike the regex approach, this properly tracks:
 *   1. {* comment *} blocks → skip
 *   2. {syntax off} ... {/syntax} blocks → skip
 *   3. Inside <script>...</script> → skip (JS code)
 *   4. Converts {% ... %} → { ... } everywhere else
 */
declare(strict_types=1);

$dry = in_array('--dry', $argv ?? []);
$baseDir = __DIR__ . '/../templates';
$count = 0; $fixed = 0;

$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iter as $file) {
    if ($file->getExtension() !== 'latte') continue;
    $path = $file->getPathname();
    $src = file_get_contents($path);
    if ($src === false) continue;
    $count++;

    $result = convertLatteDelimiters($src);
    if ($result !== $src) {
        $fixed++;
        $rel = str_replace($baseDir . '/', '', $path);
        if ($dry) {
            echo "  [DRY] {$rel}\n";
        } else {
            file_put_contents($path, $result);
            echo "  ✅ {$rel}\n";
        }
    }
}

echo "\n{$fixed}/{$count} files " . ($dry ? "would be" : "") . " fixed\n";

function convertLatteDelimiters(string $src): string
{
    $len = strlen($src);
    $out = '';
    $i = 0;

    while ($i < $len) {
        // Track: {syntax off} ... {/syntax}
        if (substr($src, $i, 13) === '{syntax off}') {
            $end = strpos($src, '{/syntax}', $i);
            if ($end === false) { $out .= substr($src, $i); break; }
            $out .= substr($src, $i, $end - $i + 10);
            $i = $end + 10;
            continue;
        }

        // Track: <script> ... </script> (JS code — don't touch)
        // Only if NOT using {syntax off} (some templates use that)
        if (substr($src, $i, 8) === '<script>' || substr($src, $i, 9) === '<script ') {
            $end = stripos($src, '</script>', $i + 8);
            if ($end === false) { $out .= substr($src, $i); break; }
            $out .= substr($src, $i, $end - $i + 9);
            $i = $end + 9;
            continue;
        }

        // Track: {* ... *} Latte comments
        if (substr($src, $i, 3) === '{* ') {
            $end = strpos($src, ' *}', $i + 3);
            if ($end === false) { $out .= substr($src, $i); break; }
            $out .= substr($src, $i, $end - $i + 3);
            $i = $end + 3;
            continue;
        }

        // Convert: {% ... %} → { ... }
        if (substr($src, $i, 2) === '{%') {
            // Find matching %}
            $j = $i + 2;
            $depth = 1;
            $inStr = false;
            $strChar = '';
            while ($j < $len && $depth > 0) {
                $c = $src[$j];
                if ($inStr) {
                    if ($c === $strChar && $src[$j-1] !== '\\') $inStr = false;
                } else {
                    if ($c === '"' || $c === "'") { $inStr = true; $strChar = $c; }
                    elseif (substr($src, $j, 2) === '{%') { $depth++; $j++; }
                    elseif (substr($src, $j, 2) === '%}') { $depth--; if ($depth === 0) break; $j++; }
                }
                $j++;
            }
            if ($depth === 0 && $j < $len) {
                // Got {% ... %} at positions i..j+1
                $inner = substr($src, $i + 2, $j - $i - 2);
                $out .= '{' . $inner . '}';
                $i = $j + 2;
                continue;
            }
        }

        $out .= $src[$i];
        $i++;
    }

    return $out;
}
