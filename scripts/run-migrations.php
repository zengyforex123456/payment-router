<?php
/**
 * scripts/run-migrations.php — Runtime migration runner
 * Called by Docker entrypoint. Idempotent: skips already-applied migrations.
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Converge\Database\MigrationRunner;

try {
    $db = new mysqli(
        getenv('DB_HOST') ?: 'mysql',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        getenv('DB_NAME') ?: 'converge'
    );
    if ($db->connect_error) {
        echo "⚠️  DB connect failed: {$db->connect_error} — skipping migrations\n";
        exit(0);
    }

    $runner = new MigrationRunner($db);
    $applied = $runner->run();

    if (empty($applied)) {
        echo "✅ No pending migrations\n";
    } else {
        echo "✅ Applied " . count($applied) . " migration(s)\n";
    }
} catch (\Throwable $e) {
    echo "❌ Migration error: {$e->getMessage()}\n";
    exit(1);
}
