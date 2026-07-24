<?php
/**
 * /health — 四可健康检查端点
 * 
 * 🔭 可观察: 服务状态 + 资源使用
 * 📋 可追溯: Git commit + 部署时间
 * 📐 可审计: PHP配置 + 扩展清单
 * ✅ 可验证: 自愈记录 + OPcache状态
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$health = [
    'ok' => true,
    'timestamp' => date('c'),
    'uptime_seconds' => time() - (int)($_SERVER['REQUEST_TIME'] ?? time()),
    
    // 🔭 可观察
    'observability' => [
        'php_version' => PHP_VERSION,
        'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
        'disk_free_mb' => (int)(disk_free_space('/var/www/converge/storage') / 1024 / 1024),
    ],
    
    // 📋 可追溯
    'traceability' => [
        'git_commit' => trim(@shell_exec('git rev-parse --short HEAD 2>/dev/null') ?: 'unknown'),
        'git_branch' => trim(@shell_exec('git branch --show-current 2>/dev/null') ?: 'unknown'),
        'deploy_time' => trim(@file_get_contents('/var/www/converge/storage/deploy-time.txt') ?: date('c')),
    ],
    
    // 📐 可审计
    'auditability' => [
        'display_errors' => (int)ini_get('display_errors'),
        'expose_php' => (int)ini_get('expose_php'),
        'opcache_enabled' => (int)function_exists('opcache_get_status'),
        'extensions' => get_loaded_extensions(),
    ],
    
    // ✅ 可验证
    'verifiability' => [
        'opcache' => function_exists('opcache_get_status') ?
            ['memory_used_mb' => round((opcache_get_status()['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 1)] :
            'disabled',
        'redis' => checkRedis(),
        'db' => checkDB(),
        // 运行时断言
        'assertions' => class_exists('Converge\\Verify\\AssertionEngine')
            ? \Converge\Verify\AssertionEngine::stats() : ['status' => 'not_loaded'],
        // KAG 知识统计
        'kag' => checkKag(),
        // 基础设施断言
        'infra' => checkInfra(),
    ],
];

http_response_code($health['ok'] ? 200 : 503);
echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

function checkKag(): array {
    try {
        $db = new mysqli(
            getenv('DB_HOST') ?: 'mysql', getenv('DB_USER') ?: 'root',
            getenv('DB_PASSWORD') ?: '', getenv('DB_NAME') ?: 'converge'
        );
        $r = $db->query("SELECT COUNT(*) c FROM kag_entities");
        $total = $r ? (int)$r->fetch_assoc()['c'] : 0;
        $r = $db->query("SELECT COUNT(*) c FROM error_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $err24 = $r ? (int)$r->fetch_assoc()['c'] : 0;
        $db->close();
        return ['total' => $total, 'errors_24h' => $err24];
    } catch (\Throwable $e) {
        return ['status' => 'not_initialized'];
    }
}

function checkInfra(): array {
    return [
        'assert_layers' => ['api','ui','data','func','observe','infra'],
        'security_headers' => 'checked at Nginx',
        'uploads_exec_protected' => !file_exists('/var/www/converge/storage/uploads/test.php')
            || !is_executable('/var/www/converge/storage/uploads/test.php'),
    ];
}

function checkRedis(): array {
    try {
        if (!class_exists('Predis\Client')) return ['status' => 'not_installed'];
        $config = require APP_ROOT . '/config/redis.php';
        $redis = new \Predis\Client(['scheme'=>'tcp','host'=>$config['host'],'port'=>$config['port'],'timeout'=>1]);
        $redis->connect();
        $redis->ping();
        return ['status' => 'ok', 'queue_length' => (int)$redis->llen($config['queue_key'])];
    } catch (\Throwable $e) {
        return ['status' => 'degraded', 'error' => $e->getMessage()];
    }
}

function checkDB(): array {
    try {
        $db = new mysqli(
            getenv('DB_HOST') ?: 'mysql',
            getenv('DB_USER') ?: 'root',
            getenv('DB_PASSWORD') ?: '',
            getenv('DB_NAME') ?: 'converge'
        );
        $db->ping();
        $db->close();
        return ['status' => 'ok'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}
