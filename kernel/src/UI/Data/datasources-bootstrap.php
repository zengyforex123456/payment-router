<?php
declare(strict_types=1);

/**
 * 数据源注册引导
 * 在页面中 require_once 此文件即可使用所有已注册数据源。
 *
 * 实际项目中应该在 bootstrap.php 或 ModuleLoader 中注册。
 */
// autoload 由调用方（页面/CLI）加载，此处不重复 require
use Converge\UI\Data\DataSourceRegistry;
use Converge\UI\Data\StaticDataSource;

// ─── Demo 数据源（静态数据，零 DB 依赖）──────────────────────

// 用户列表 — Table 演示
DataSourceRegistry::override('demo.users', new StaticDataSource(
    [
        ['name' => 'Alice Chen', 'email' => 'alice@example.com', 'role' => 'Admin', 'status' => 'Active'],
        ['name' => 'Bob Wang', 'email' => 'bob@example.com', 'role' => 'Editor', 'status' => 'Active'],
        ['name' => 'Carol Li', 'email' => 'carol@example.com', 'role' => 'Viewer', 'status' => 'Inactive'],
        ['name' => 'David Zhang', 'email' => 'david@example.com', 'role' => 'Editor', 'status' => 'Active'],
        ['name' => 'Eva Wu', 'email' => 'eva@example.com', 'role' => 'Admin', 'status' => 'Pending'],
    ],
    [
        ['key' => 'name', 'label' => 'Name'],
        ['key' => 'email', 'label' => 'Email'],
        ['key' => 'role', 'label' => 'Role'],
        ['key' => 'status', 'label' => 'Status'],
    ]
));

// 订单列表 — Table 演示
DataSourceRegistry::override('demo.orders', new StaticDataSource(
    [
        ['id' => '#1001', 'customer' => 'Acme Corp', 'amount' => '$1,200', 'status' => 'Completed', 'date' => '2026-07-15'],
        ['id' => '#1002', 'customer' => 'Globex Inc', 'amount' => '$850', 'status' => 'Pending', 'date' => '2026-07-16'],
        ['id' => '#1003', 'customer' => 'Initech', 'amount' => '$2,400', 'status' => 'Completed', 'date' => '2026-07-16'],
        ['id' => '#1004', 'customer' => 'Umbrella', 'amount' => '$620', 'status' => 'Failed', 'date' => '2026-07-17'],
        ['id' => '#1005', 'customer' => 'Stark Ind', 'amount' => '$3,100', 'status' => 'Completed', 'date' => '2026-07-17'],
    ],
    [
        ['key' => 'id', 'label' => 'Order'],
        ['key' => 'customer', 'label' => 'Customer'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'status', 'label' => 'Status'],
        ['key' => 'date', 'label' => 'Date'],
    ]
));

// Dashboard 指标 — Card 演示
DataSourceRegistry::override('demo.metrics.users', new StaticDataSource(
    [['value' => '12,847', 'trend' => '+18.2%']],
    [['key' => 'value', 'label' => 'Value']]
));
DataSourceRegistry::override('demo.metrics.revenue', new StaticDataSource(
    [['value' => '$48,320', 'trend' => '+12.5%']],
    [['key' => 'value', 'label' => 'Value']]
));
DataSourceRegistry::override('demo.metrics.conversion', new StaticDataSource(
    [['value' => '3.24%', 'trend' => '-0.8%']],
    [['key' => 'value', 'label' => 'Value']]
));

// ─── MySQL Demo（仅在 DB 可用时注册）──────────────────────
try {
    $demoDb = null;
    // Try to get DB from global scope (bootstrap.php, config, etc.)
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof \mysqli) {
        $demoDb = $GLOBALS['db'];
    } elseif (function_exists('getDb') && ($db = getDb()) instanceof \mysqli) {
        $demoDb = $db;
    }
    if ($demoDb && $demoDb->ping()) {
        // Real MySQL data source with {tenant} auto-filtering
        DataSourceRegistry::override('mysql.users', new MysqlDataSource(
            $demoDb,
            'SELECT id, username, email, role, status, created_at FROM users WHERE tenant_id = {tenant} ORDER BY created_at DESC LIMIT 100',
            '',
            [
                ['key' => 'username', 'label' => 'Username'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'role', 'label' => 'Role'],
                ['key' => 'status', 'label' => 'Status'],
            ]
        ));
    }
} catch (\Throwable $e) {
    // DB not available — MySQL sources won't be registered, Static demos still work
    error_log('[datasources] MySQL not available, skipping mysql.* sources');
}
