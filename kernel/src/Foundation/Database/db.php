<?php
/**
 * db() — 数据库连接工厂 (替代裸 new mysqli)
 *
 * 返回 DatabaseInterface 实例。内部管理连接生命周期。
 * 禁止外部 close() — 接口不暴露此方法。
 *
 * 用法:
 *   $db = db();                    // 默认从 config.php 常量读取
 *   $db = db('custom_db');         // 指定数据库
 *   $result = $db->query('SELECT ...');
 *   $stmt = $db->prepare('SELECT ... WHERE id = ?');
 */
declare(strict_types=1);

use Converge\Contracts\DatabaseInterface;
use Converge\Foundation\System\MysqlAdapter;

/**
 * 获取数据库连接。同一请求内多次调用返回同一连接。
 */
function db(string $database = ''): DatabaseInterface
{
    static $instances = [];

    $key = $database ?: (defined('DB_NAME') ? DB_NAME : 'converge');

    if (!isset($instances[$key])) {
        $host = (defined('DB_HOST') ? DB_HOST : getenv('DB_HOST')) ?: '127.0.0.1';
        $user = (defined('DB_USER') ? DB_USER : getenv('DB_USER')) ?: 'root';
        $pass = (defined('DB_PASSWORD') ? DB_PASSWORD : getenv('DB_PASSWORD')) ?: '';
        $name = $database ?: ((defined('DB_NAME') ? DB_NAME : getenv('DB_NAME')) ?: 'converge');

        $port = (int)(defined('DB_PORT') ? DB_PORT : getenv('DB_PORT')) ?: 3306;
        $instances[$key] = new MysqlAdapter($host, $user, $pass, $name, $port);
    }

    return $instances[$key];
}
