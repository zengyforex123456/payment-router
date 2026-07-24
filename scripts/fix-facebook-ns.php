<?php
$root = dirname(__DIR__);
$updated = 0;

// Fix namespaces in moved files
$infraDir = "$root/modules/FacebookCost/Infrastructure";
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($infraDir));
foreach ($iter as $file) {
    if ($file->getExtension() !== 'php') continue;
    $c = file_get_contents($file->getPathname());
    $o = $c;
    $c = str_replace('namespace Converge\\Facebook;', 'namespace Converge\\Modules\\FacebookCost\\Infrastructure;', $c);
    $c = str_replace('namespace Converge\\Facebook\\Cost;', 'namespace Converge\\Modules\\FacebookCost\\Infrastructure\\Cost;', $c);
    $c = str_replace('namespace Converge\\Facebook\\Insights;', 'namespace Converge\\Modules\\FacebookCost\\Infrastructure\\Insights;', $c);
    $c = str_replace('Converge\\Facebook\\', 'Converge\\Modules\\FacebookCost\\Infrastructure\\', $c);
    if ($c !== $o) { file_put_contents($file->getPathname(), $c); $updated++; }
}

// Fix references in codebase
foreach (['app','modules','public','bin','tools'] as $dir) {
    $d = "$root/$dir";
    if (!is_dir($d)) continue;
    $iter2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d));
    foreach ($iter2 as $file) {
        if ($file->getExtension() !== 'php') continue;
        if (str_contains($file->getPathname(), 'vendor')) continue;
        if (str_contains($file->getPathname(), 'FacebookCost')) continue;
        $c = file_get_contents($file->getPathname());
        $o = $c;
        $c = str_replace('Converge\\Facebook\\', 'Converge\\Modules\\FacebookCost\\Infrastructure\\', $c);
        if ($c !== $o) { file_put_contents($file->getPathname(), $c); $updated++; echo "  [ref] " . str_replace($root . '/', '', $file->getPathname()) . "\n"; }
    }
}
echo "Facebook namespace: $updated files\n";
