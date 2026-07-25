<?php
/**
 * run-migrations-standalone.php — 零依赖迁移执行器
 *
 * 用法: php scripts/run-migrations-standalone.php
 * 不依赖 vendor/autoload.php，直接 new mysqli。
 * 适用于 standalone PHP 容器（payment-router 等）。
 */
declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('DB_PORT') ?: 3306);
$name = getenv('DB_NAME') ?: 'payment_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';

$db = new mysqli($host, $user, $pass, $name, $port);
if ($db->connect_error) {
    echo "❌ Connect: {$db->connect_error}\n";
    exit(1);
}

// 创建迁移追踪表
$db->query("CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 扫描迁移文件
$migrationsDir = __DIR__ . '/../database/migrations';
if (!is_dir($migrationsDir)) {
    echo "❌ Not found: $migrationsDir\n";
    exit(1);
}

$files = glob("$migrationsDir/*.sql");
sort($files);

// 获取已应用的迁移
$applied = [];
$r = $db->query("SELECT migration FROM migrations ORDER BY id");
while ($row = $r->fetch_assoc()) { $applied[] = $row['migration']; }

$newApplied = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) continue;

    echo "📦 $name... ";
    $sql = file_get_contents($file);

    // 分割多语句
    foreach (explode(';', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        if (!$db->query($stmt)) {
            echo "❌ {$db->error} [$stmt]\n";
            $db->close();
            exit(1);
        }
    }

    // 记录
    $stmt = $db->prepare("INSERT INTO migrations (migration) VALUES (?)");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    echo "✅\n";
    $newApplied++;
}

echo $newApplied === 0 ? "✅ No pending migrations\n" : "✅ Applied $newApplied migration(s)\n";
$db->close();
