<?php
/**
 * check-contract-migration.php — 找出仍直接 use 已契约化类的文件
 *
 * D 方案迁移追踪: 列出哪些文件还没切换到 ContractBus
 */
$root = dirname(__DIR__);

// 已契约化的类 → 接口
$contractMap = [
    'Converge\\Settings\\SettingsManager' => 'Converge\\Contracts\\Settings\\SettingsInterface',
    'Converge\\Tracking\\Redirectless\\ClickRecorder' => 'Converge\\Contracts\\Tracking\\ClickRecorderInterface',
    'Converge\\Tracking\\Infrastructure\\ConversionTracker' => 'Converge\\Contracts\\Tracking\\ConversionTrackerInterface',
    'Converge\\Funnel\\LpDeployer' => 'Converge\\Contracts\\Funnel\\LpDeployerInterface',
    'Converge\\Funnel\\CampaignBridge' => 'Converge\\Contracts\\Campaign\\CampaignBridgeInterface',
    'Converge\\Enrichment\\GeoLocator' => 'Converge\\Contracts\\Enrichment\\GeoLocatorInterface',
];

// 排除文件
$exclude = ['app/Contracts/', 'vendor/', 'storage/'];

$total = 0;
foreach ($contractMap as $concrete => $interface) {
    $count = 0;
    foreach (['public', 'app', 'modules'] as $dir) {
        $path = $root . '/' . $dir;
        if (!is_dir($path)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $fp = str_replace('\\', '/', $f->getRealPath());
            foreach ($exclude as $ex) if (str_contains($fp, $ex)) continue 2;
            $c = file_get_contents($fp);
            if (str_contains($c, 'use ' . $concrete) || str_contains($c, 'new \\' . $concrete)) {
                $count++;
                $total++;
            }
        }
    }
    if ($count > 0) echo "  $concrete → {$count} files still need migration\n";
    else echo "  ✅ $concrete — all migrated\n";
}

echo "\nTotal: {$total} files need migration\n";
