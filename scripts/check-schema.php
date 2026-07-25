<?php
/**
 * check-schema.php — 数据库 Schema 安全验证
 *
 * 用 SHOW COLUMNS (MySQL 5.5+ 兼容) 替代 ALTER TABLE ADD COLUMN IF NOT EXISTS (需 8.0.29+)。
 * 部署前运行: php scripts/check-schema.php  → 输出缺失列 + 生成安全迁移 SQL
 *
 * 用法:
 *   php scripts/check-schema.php                  # 检查所有表
 *   php scripts/check-schema.php --fix            # 输出修复 SQL
 *   php scripts/check-schema.php --table users    # 只检查单表
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

// ─── 预期 schema 定义 ───
$expected = [
    'users' => [
        'id'         => 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
        'username'   => 'VARCHAR(50) NOT NULL',
        'pass_hash'  => 'VARCHAR(255) NOT NULL',
        'email'      => 'VARCHAR(255) NOT NULL',
        'company'    => 'VARCHAR(255) DEFAULT NULL',
        'plan'       => 'VARCHAR(20) DEFAULT \'free\'',
        'role_id'    => 'INT DEFAULT NULL',
        'is_active'  => 'TINYINT(1) DEFAULT 1',
        'timezone'   => 'VARCHAR(50) DEFAULT \'UTC\'',
        'currency'   => 'CHAR(3) DEFAULT \'USD\'',
        'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NULL ON UPDATE CURRENT_TIMESTAMP',
    ],
    'payment_router_users' => [
        'id'         => 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
        'email'      => 'VARCHAR(255) NOT NULL',
        'pass_hash'  => 'VARCHAR(255) NOT NULL',
        'tier'       => 'VARCHAR(20) DEFAULT \'community\'',
        'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NULL ON UPDATE CURRENT_TIMESTAMP',
    ],
    'payment_router_tenant_config' => [
        'id'         => 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
        'tenant_id'  => 'INT UNSIGNED NOT NULL UNIQUE',
        'tier'       => 'VARCHAR(20) DEFAULT \'community\'',
        'status'     => 'VARCHAR(20) DEFAULT \'active\'',
        'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
    ],
];

$fix   = in_array('--fix', $argv, true);
$table = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--table=')) { $table = substr($arg, 8); break; }
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) { die("❌ CONNECT: {$db->connect_error}\n"); }

$issues = 0; $fixes = [];

foreach ($expected as $tbl => $cols) {
    if ($table !== null && $tbl !== $table) continue;

    // 检查表是否存在
    $r = $db->query("SHOW TABLES LIKE '$tbl'");
    if ($r->num_rows === 0) {
        echo "❌ $tbl: TABLE MISSING\n";
        $issues++;
        continue;
    }

    // 获取现有列
    $existingCols = [];
    $r = $db->query("SHOW COLUMNS FROM $tbl");
    while ($row = $r->fetch_assoc()) { $existingCols[$row['Field']] = $row['Type']; }

    foreach ($cols as $col => $def) {
        if (!isset($existingCols[$col])) {
            echo "❌ $tbl.$col: MISSING (expected: $def)\n";
            $fixes[] = "ALTER TABLE $tbl ADD COLUMN $col $def;";
            $issues++;
        }
    }

    if (!array_key_exists($col, $cols ?? []) && $table === $tbl) {
        echo "✅ $tbl: all " . count($cols) . " columns present\n";
    }
}

echo "\n";
if ($issues === 0) {
    echo "✅ Schema OK — all expected columns present\n";
} else {
    echo "❌ $issues schema issue(s) found\n";

    if ($fix) {
        echo "\n-- Run these SQL statements to fix:\n";
        foreach ($fixes as $sql) { echo "$sql\n"; }
    } else {
        echo "   Run with --fix to generate repair SQL\n";
    }
}

$db->close();
exit($issues > 0 ? 1 : 0);
