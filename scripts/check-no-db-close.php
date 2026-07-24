<?php
/** 检查 PHP 文件中禁止的 $db->close() / $authDb->close() */
$root = dirname(__DIR__);
$violations = [];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/public', RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $lines = file($f->getRealPath());
    foreach ($lines as $no => $line) {
        if (preg_match('/(\$db|\$authDb|\$db2|\$mysqli)\s*->\s*close\s*\(/', $line)) {
            $violations[] = basename($f->getFilename()) . ':' . ($no + 1) . ' → ' . trim($line);
        }
    }
}

if ($violations) {
    echo "❌ " . count($violations) . " close() calls — remove them (PHP auto-closes)\n\n";
    foreach ($violations as $v) echo "  $v\n";
    exit(1);
}
echo "✅ No db->close() found\n";
