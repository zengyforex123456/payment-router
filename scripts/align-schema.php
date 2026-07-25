<?php
/**
 * align-schema.php — 声明式 Schema 对齐
 *
 * 读 database/schema.json → 对比实际 DB → 生成并执行 ALTER TABLE。
 * 只管理 schema.json 中定义的表，其他表不动。
 * 用 SHOW COLUMNS (MySQL 5.5+) 而非 IF NOT EXISTS (需 8.0.29+)。
 *
 * 用法:
 *   php scripts/align-schema.php              # 对齐所有定义的表
 *   php scripts/align-schema.php --dry-run    # 只输出 SQL，不执行
 *   php scripts/align-schema.php --table users # 只对齐单表
 *   php scripts/align-schema.php --json       # JSON 输出（CI/CD 用）
 */
declare(strict_types=1);

$schemaFile = __DIR__ . '/../database/schema.json';
if (!file_exists($schemaFile)) { die("❌ schema.json not found at $schemaFile\n"); }
$schema = json_decode(file_get_contents($schemaFile), true);
if (!$schema || !isset($schema['tables'])) { die("❌ Invalid schema.json\n"); }

$tables = $schema['tables'];
$dryRun = in_array('--dry-run', $argv, true);
$jsonOut = in_array('--json', $argv, true);
$filterTable = null;
foreach ($argv as $arg) { if (str_starts_with($arg, '--table=')) { $filterTable = substr($arg, 8); break; } }

// ─── 加载 DB 凭据 ───
$configFile = __DIR__ . '/../config/config.php';
if (file_exists($configFile)) require_once $configFile;
$host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: '127.0.0.1');
$user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: 'root');
$pass = defined('DB_PASSWORD') ? DB_PASSWORD : (getenv('DB_PASSWORD') ?: '');
$dbName = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'converge');

$db = new mysqli($host, $user, $pass, $dbName, (int)(defined('DB_PORT') ? DB_PORT : (getenv('DB_PORT') ?: 3306)));
if ($db->connect_error) {
    $err = "DB connect: {$db->connect_error}";
    if ($jsonOut) { echo json_encode(['ok'=>false,'error'=>$err]) . "\n"; exit(1); }
    die("❌ $err\n");
}

// ─── 获取现有表列表 ───
$existingTables = [];
$r = $db->query("SHOW TABLES");
while ($row = $r->fetch_row()) { $existingTables[] = $row[0]; }

$actions = []; // ['type'=>'create_table'|'add_column'|'ok', 'table'=>..., 'detail'=>...]
$sqlStatements = [];

foreach ($tables as $tbl => $def) {
    if ($filterTable !== null && $tbl !== $filterTable) continue;
    $cols = $def['columns'] ?? [];
    $idxes = $def['indexes'] ?? [];
    $engine = $def['engine'] ?? 'InnoDB DEFAULT CHARSET=utf8mb4';

    // ─── 表不存在 → CREATE TABLE ───
    if (!in_array($tbl, $existingTables, true)) {
        $colDefs = [];
        foreach ($cols as $col => $type) { $colDefs[] = "$col $type"; }
        foreach ($idxes as $idx) { $colDefs[] = $idx; }
        $sql = "CREATE TABLE $tbl (\n  " . implode(",\n  ", $colDefs) . "\n) ENGINE=$engine";
        $sqlStatements[] = $sql;
        $actions[] = ['type'=>'create_table','table'=>$tbl,'detail'=>count($cols).' columns'];
        continue;
    }

    // ─── 表存在 → 检查列 ───
    $existingCols = [];
    $r = $db->query("SHOW COLUMNS FROM $tbl");
    while ($row = $r->fetch_assoc()) { $existingCols[$row['Field']] = $row['Type']; }

    $missingCols = [];
    foreach ($cols as $col => $type) {
        if (!array_key_exists($col, $existingCols)) {
            $missingCols[] = $col;
            $sqlStatements[] = "ALTER TABLE $tbl ADD COLUMN $col $type";
            $actions[] = ['type'=>'add_column','table'=>$tbl,'column'=>$col,'definition'=>$type];
        }
    }

    if (empty($missingCols)) {
        $actions[] = ['type'=>'ok','table'=>$tbl,'detail'=>count($cols).' columns present'];
    }
}

// ─── 输出 ───
if ($jsonOut) {
    echo json_encode([
        'ok' => empty($sqlStatements),
        'actions' => $actions,
        'sql_count' => count($sqlStatements),
        'sql' => $dryRun ? $sqlStatements : [],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(empty($sqlStatements) ? 0 : 1);
}

if (empty($sqlStatements)) {
    echo "✅ Schema aligned — all " . count($tables) . " tables match\n";
    exit(0);
}

echo "📐 " . count($sqlStatements) . " change(s) needed:\n\n";
foreach ($actions as $a) {
    $sym = match($a['type']) { 'create_table'=>'🆕', 'add_column'=>'➕', 'ok'=>'✅', default=>'  ' };
    echo "  $sym {$a['table']}: {$a['type']}";
    if (isset($a['column'])) echo " {$a['column']}";
    if (isset($a['detail'])) echo " ({$a['detail']})";
    echo "\n";
}

if ($dryRun) {
    echo "\n--- Dry run SQL (not executed) ---\n";
    foreach ($sqlStatements as $sql) { echo "$sql;\n"; }
    exit(1);
}

// ─── 执行 ───
echo "\nExecuting...\n";
foreach ($sqlStatements as $sql) {
    echo "  $sql\n";
    if (!$db->query($sql)) {
        echo "  ❌ {$db->error}\n";
        $db->close();
        exit(1);
    }
}
echo "✅ Schema aligned successfully\n";
$db->close();
