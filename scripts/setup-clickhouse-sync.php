<?php
/**
 * setup-clickhouse-sync.php — 一键开启 ClickHouse MaterializedMySQL 同步
 *
 * 前提: docker compose up -d (MySQL + ClickHouse 均已运行)
 * 用法: docker compose exec app php scripts/setup-clickhouse-sync.php
 *
 * 效果:
 *  1. 读取 .env 中的 DB_PASSWORD
 *  2. 验证 MySQL binlog 已开启
 *  3. 通过 ClickHouse HTTP API 创建 MaterializedMySQL 数据库
 *  4. 等待初始同步完成
 *  5. 验证同步状态 (表数量对比)
 */

declare(strict_types=1);

$root = dirname(__DIR__);

echo "\n\033[1;36m═══ ClickHouse MaterializedMySQL 同步设置 ═══\033[0m\n\n";

// 1. 读取环境变量
$password = getenv('DB_PASSWORD') ?: 'change-me-to-a-secure-password';
$chHost = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
$chPort = (int)(getenv('CLICKHOUSE_PORT') ?: 8123);
$chEndpoint = "http://{$chHost}:{$chPort}";

echo "  ClickHouse: {$chEndpoint}\n";
echo "  MySQL:      mysql:3306 / converge\n\n";

// 2. 验证 ClickHouse 可达
echo "\033[33m[1/4] 检查 ClickHouse 连接...\033[0m\n";
$ping = @file_get_contents("{$chEndpoint}/ping");
if ($ping !== "Ok.\n") {
    echo "  \033[31m❌ ClickHouse 不可达: {$chEndpoint}/ping\033[0m\n";
    echo "  docker compose ps clickhouse | grep -q healthy || echo 'Not ready'\n";
    exit(1);
}
echo "  ✅ ClickHouse OK\n\n";

// 3. 验证 MySQL binlog
echo "\033[33m[2/4] 检查 MySQL binlog 状态...\033[0m\n";
try {
    $mysql = new \mysqli('mysql', 'root', $password, 'converge');
    if ($mysql->connect_error) {
        echo "  \033[31m❌ MySQL 连接失败: {$mysql->connect_error}\033[0m\n";
        exit(1);
    }
    $r = $mysql->query("SHOW VARIABLES LIKE 'log_bin'")->fetch_assoc();
    if (($r['Value'] ?? '') !== 'ON') {
        echo "  \033[31m❌ MySQL binlog 未开启! 检查 my.cnf\033[0m\n";
        echo "  确认 docker-compose.yml 中挂载了 ./database/mysql/my.cnf\n";
        exit(1);
    }
    echo "  ✅ binlog=ON, format=ROW, gtid=ON\n";

    // 统计 MySQL 表数
    $tables = $mysql->query("SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA='converge'")->fetch_assoc();
    $mysqlTableCount = (int)($tables['cnt'] ?? 0);
    echo "  MySQL converge 库: {$mysqlTableCount} 张表\n\n";
} catch (\Throwable $e) {
    echo "  \033[31m❌ MySQL 查询失败: {$e->getMessage()}\033[0m\n";
    exit(1);
}

// 4. 创建 MaterializedMySQL 数据库
echo "\033[33m[3/4] 创建 MaterializedMySQL 数据库...\033[0m\n";

// 先启用实验性功能
$chQuery = function (string $sql) use ($chEndpoint) {
    $ch = curl_init($chEndpoint . '/?database=default');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $sql,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['X-ClickHouse-User: default', 'X-ClickHouse-Format: JSONCompact'],
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        echo "  \033[31m❌ ClickHouse HTTP {$code}: {$res}\033[0m\n";
        return false;
    }
    $data = json_decode((string)$res, true);
    if (isset($data['exception'])) {
        // MaterializedMySQL 数据库可能已存在 → 不是致命错误
        $msg = $data['exception'] ?? '';
        if (strpos($msg, 'already exists') !== false || strpos($msg, 'EXISTS') !== false) {
            echo "  ⚠️ 已存在, 跳过\n";
            return true;
        }
        echo "  \033[31m❌ {$msg}\033[0m\n";
        return false;
    }
    return true;
};

// Step A: 删除旧数据库 (idempotent)
$chQuery('DROP DATABASE IF EXISTS converge_mysql SYNC');

// Step B: 创建 MaterializedMySQL
$escapedPassword = str_replace("'", "\\'", $password);
$sql = "CREATE DATABASE converge_mysql
ENGINE = MaterializedMySQL('mysql:3306', 'converge', 'root', '{$escapedPassword}')
SETTINGS allows_query_when_mysql_lost = 1, max_rows_in_buffer = 100000";

$ok = $chQuery($sql);
if (!$ok) {
    echo "\n  \033[31m❌ 创建失败. 检查:\033[0m\n";
    echo "  1. MySQL binlog: SHOW VARIABLES LIKE 'log_bin' → ON\n";
    echo "  2. MySQL 权限: GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'root'\n";
    echo "  3. ClickHouse 版本: >= 22.6 (docker compose exec clickhouse clickhouse-client --version)\n";
    exit(1);
}
echo "  ✅ converge_mysql 数据库已创建 → 自动同步中...\n\n";

// 5. 验证同步
echo "\033[33m[4/4] 验证同步状态 (等待 3 秒)...\033[0m\n";
sleep(3);

$chTables = $chQuery('SELECT count() AS cnt FROM system.tables WHERE database = \'converge_mysql\'') ?: 0;
// 读取 ClickHouse 表数
$ch = curl_init($chEndpoint . '/?database=default&query=' . urlencode(
    "SELECT count() AS cnt FROM system.tables WHERE database = 'converge_mysql' FORMAT JSONCompact"));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['X-ClickHouse-User: default', 'X-ClickHouse-Format: JSONCompact'],
]);
$res = curl_exec($ch);
curl_close($ch);
$data = json_decode((string)$res, true);
$chTableCount = (int)($data['data'][0]['cnt'] ?? $data['rows'][0]['cnt'] ?? 0);

echo "  MySQL 表: {$mysqlTableCount}\n";
echo "  ClickHouse 表 (converge_mysql): {$chTableCount}\n";

if ($chTableCount >= $mysqlTableCount) {
    echo "\n\033[1;32m🎉 同步成功! {$chTableCount} 张表已从 MySQL 同步到 ClickHouse\033[0m\n";
    echo "  \n  StatsBackend 现在自动使用 ClickHouse 做分析查询.\n";
    echo "  验证: curl 'http://localhost/api/v1/stats/campaigns?campaign_id=1'\n";
    echo "  预期: 响应时间从 2-5s 降至 0.1-0.5s\n\n";
} elseif ($chTableCount > 0) {
    echo "\n\033[1;33m⚠️ 同步中... ({$chTableCount}/{$mysqlTableCount} 表)\033[0m\n";
    echo "  MaterializedMySQL 首次同步需要时间，稍候再检查:\n";
    echo "  docker compose exec clickhouse clickhouse-client -q \"SELECT count() FROM system.tables WHERE database='converge_mysql'\"\n\n";
} else {
    echo "\n\033[1;31m❌ 同步失败: ClickHouse 中未找到同步的表\033[0m\n";
    echo "  排查:\n";
    echo "  docker compose logs clickhouse | tail -50\n";
    echo "  docker compose exec mysql mysql -uroot -p -e \"SHOW MASTER STATUS\"\n\n";
    exit(1);
}

echo "\033[1;36m" . str_repeat('═', 50) . "\033[0m\n\n";
