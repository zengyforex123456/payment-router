<?php
/** cron-cleanup.php — 定时数据清理 (建议每日凌晨运行) */
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

echo date('Y-m-d H:i:s') . " — Data Cleanup\n";

if (class_exists('Converge\DataRetention\ClickDataCleanup')) {
    $deleted = \Converge\DataRetention\ClickDataCleanup::run(db()->raw());
    echo "  Deleted: {$deleted} click batches\n";
} else {
    echo "  DataRetention module not loaded\n";
}
