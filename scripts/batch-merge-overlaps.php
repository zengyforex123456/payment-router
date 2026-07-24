<?php
/** Batch merge all app/X + modules/X overlaps */
declare(strict_types=1);

$root = dirname(__DIR__);
$modules = ['GeoIP','Campaign','Funnel','Intent','FacebookCost','Growth',
    'Enrichment','DataRetention','Copy','CopyEvaluator','TrafficSource',
    'Traceability','Settings','Theme','Session','Http','Performance','Utils'];

$merged = 0;
$skipped = 0;

foreach ($modules as $module) {
    $appDir = "$root/app/{$module}";
    $modDir = "$root/modules/{$module}";

    if (!is_dir($appDir) || !is_dir($modDir)) {
        echo "SKIP $module (no overlap)\n";
        $skipped++;
        continue;
    }

    $appFiles = count(glob("$appDir/**/*.php"));
    $modFiles = count(glob("$modDir/**/*.php"));

    // Step 1: Copy app/X/* → modules/X/Infrastructure/
    $infraDir = "$modDir/Infrastructure";
    if (!is_dir($infraDir)) mkdir($infraDir, 0755, true);
    recurseCopy($appDir, $infraDir);

    // Step 2: Run namespace migrator
    $cmd = sprintf('php "%s/scripts/namespace-migrate.php" %s 2>&1',
        $root, escapeshellarg($module));
    exec($cmd, $output, $rc);

    // Step 3: Delete old app/X/
    recurseDelete($appDir);

    echo "MERGED $module: $appFiles app + $modFiles module → modules/\n";
    if ($rc === 0) echo "  OK\n"; else echo "  WARN (rc=$rc)\n";
    $merged++;
}

echo "\nDone: $merged merged, $skipped skipped\n";
echo "Now run: composer dump-autoload && php bin/tool sync\n";

function recurseCopy(string $src, string $dst): void {
    if (!is_dir($src)) return;
    $dir = opendir($src);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') continue;
        $srcPath = "$src/$file";
        $dstPath = "$dst/$file";
        if (is_dir($srcPath)) {
            if (!is_dir($dstPath)) mkdir($dstPath, 0755, true);
            recurseCopy($srcPath, $dstPath);
        } else {
            copy($srcPath, $dstPath);
        }
    }
    closedir($dir);
}

function recurseDelete(string $dir): void {
    if (!is_dir($dir)) return;
    $items = array_diff(scandir($dir) ?: [], ['.', '..']);
    foreach ($items as $item) {
        $path = "$dir/$item";
        is_dir($path) ? recurseDelete($path) : unlink($path);
    }
    rmdir($dir);
}
