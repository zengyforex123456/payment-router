<?php
declare(strict_types=1);
namespace Converge\Foundation\System;

/**
 * ConnectionManager — single DB connection factory
 *
 * 替代 bootstrap.php 中重复的 new \mysqli()。
 * 读取 $_ENV 一次，全局复用。支持测试时注入 mock。
 *
 * 用法:
 *   $db = ConnectionManager::get();
 *   $repo = new MysqlTenantRepository($db);
 */
final class ConnectionManager
{
    private static ?\mysqli $instance = null;

    /** 测试注入点 — 传入 mock 连接替代真实 MySQL */
    public static function inject(\mysqli $mock): void
    {
        self::$instance = $mock;
    }

    /** 重置（测试清理） */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function get(): \mysqli
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASSWORD'] ?? '';
        $name = $_ENV['DB_NAME'] ?? 'converge';
        $port = (int) ($_ENV['DB_PORT'] ?? 3306);

        // 报告错误而非静默继续
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        self::$instance = new \mysqli($host, $user, $pass, $name, $port);
        self::$instance->set_charset('utf8mb4');
        self::$instance->query("SET time_zone = '+00:00'");

        return self::$instance;
    }
}
