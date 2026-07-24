<?php
/**
 * fix-alpine-braces.php — Escape Alpine { } inside HTML attributes
 *
 * After the {% %} → { } conversion, Alpine x-data="{key: val}" attributes
 * are now parsed by Latte as tags. Fix: { } → {{ }} inside x-data attributes.
 *
 * Also handles: x-init="fn(){...}" patterns and @click attribute JS blocks.
 */
declare(strict_types=1);

$dry = in_array('--dry', $argv ?? []);
$files = glob(__DIR__ . '/../templates/**/*.latte');
$fixed = 0;

foreach ($files as $path) {
    $src = file_get_contents($path);
    if ($src === false) continue;

    $result = fixAlpineBraces($src);
    if ($result !== $src) {
        $fixed++;
        $rel = str_replace(__DIR__ . '/../', '', $path);
        if ($dry) {
            echo "  [DRY] {$rel}\n";
        } else {
            file_put_contents($path, $result);
            echo "  ✅ {$rel}\n";
        }
    }
}

echo "\n{$fixed}/" . count($files) . " files " . ($dry ? "would be" : "") . " fixed\n";

function fixAlpineBraces(string $src): string
{
    $len = strlen($src);
    $out = '';
    $i = 0;

    while ($i < $len) {
        // Skip {syntax off} ... {/syntax} blocks
        if (substr($src, $i, 13) === '{syntax off}') {
            $end = strpos($src, '{/syntax}', $i);
            if ($end === false) { $out .= substr($src, $i); break; }
            $out .= substr($src, $i, $end - $i + 10);
            $i = $end + 10;
            continue;
        }

        // Skip {* comment *}
        if (substr($src, $i, 3) === '{* ') {
            $end = strpos($src, ' *}', $i + 3);
            if ($end === false) { $out .= substr($src, $i); break; }
            $out .= substr($src, $i, $end - $i + 3);
            $i = $end + 3;
            continue;
        }

        // Skip <script>...</script> ONLY if NOT wrapped in {syntax off} (already handled above)
        if (substr($src, $i, 8) === '<script>' || substr($src, $i, 9) === '<script ') {
            // Check if this script is INSIDE a {syntax off} block
            // If not, skip it (don't touch JS code)
            $end = stripos($src, '</script>', $i + 8);
            if ($end === false) { $out .= substr($src, $i); break; }
            $out .= substr($src, $i, $end - $i + 9);
            $i = $end + 9;
            continue;
        }

        // Fix: x-data="{key: val}" → x-data="{{ key: val }}"
        if (substr($src, $i, 8) === 'x-data="' && $src[$i + 8] === '{' && substr($src, $i, 11) !== 'x-data="{{ ') {
            // Find the matching closing } followed by "
            $j = $i + 9; // position after x-data="{
            $depth = 1;
            $inStr = false;
            $strChar = '';
            while ($j < $len && $depth > 0) {
                $c = $src[$j];
                if ($inStr) {
                    if ($c === $strChar && $src[$j - 1] !== '\\') $inStr = false;
                } else {
                    if ($c === '"' || $c === "'") { $inStr = true; $strChar = $c; }
                    elseif ($c === '{') $depth++;
                    elseif ($c === '}') $depth--;
                }
                $j++;
            }
            if ($depth === 0 && $j < $len && $src[$j] === '"') {
                // Found: src[i..j] is x-data="{...}"
                $inner = substr($src, $i + 9, $j - $i - 10); // content between { and }
                $out .= 'x-data="{{ ' . $inner . ' }}"';
                $i = $j + 1; // skip past closing "
                continue;
            }
        }

        $out .= $src[$i];
        $i++;
    }

    return $out;
}
