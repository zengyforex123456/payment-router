<?php
/**
 * 数据库迁移脚本 — 在容器内运行
 * 用法: docker exec source-app-1 php /var/www/converge/scripts/migrate-db.php
 */
$host = getenv('DB_HOST') ?: 'mysql';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: 'root';
$db = getenv('DB_NAME') ?: 'converge';
$dir = __DIR__ . '/../database/migrations/';

$mysqli = new mysqli($host, $user, $pass, $db);
if ($mysqli->connect_error) { die("Connect failed: " . $mysqli->connect_error . "\n"); }
echo "Connected to MySQL @ $host\n";

// 迁移追踪表
$mysqli->query("CREATE TABLE IF NOT EXISTS _migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$files = glob($dir . '*.sql');
sort($files);
$done = 0; $skip = 0; $fail = 0;

foreach ($files as $file) {
    $name = basename($file);
    $check = $mysqli->query("SELECT id FROM _migrations WHERE filename = '" . $mysqli->real_escape_string($name) . "'");
    if ($check && $check->num_rows > 0) { $skip++; continue; }

    $sql = file_get_contents($file);
    if (trim($sql) === '') continue;

    // 按分号拆分语句，跳过注释
    $statements = preg_split('/;\s*\n/', $sql);
    $allOk = true;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
        if (!$mysqli->query($stmt)) {
            echo "FAIL: $name — " . $mysqli->error . "\n";
            $allOk = false; $fail++;
            break;
        }
    }
    if ($allOk) {
        $mysqli->query("INSERT INTO _migrations (filename) VALUES ('" . $mysqli->real_escape_string($name) . "')");
        $done++;
        if ($done % 10 === 0) echo "  $done migrations done...\n";
    }
}
echo "\nDone: $done executed, $skip skipped, $fail failed\n";
$mysqli->close();
