<?php
/**
 * MysqlAdapter — mysqli 数据库适配器
 *
 * implements DatabaseInterface, 将 mysqli 调用封装为六边形端口。
 * 替换: new MysqlAdapter($host, $user, $pass, $db) → new PostgresAdapter(...)
 */
declare(strict_types=1);

namespace Converge\Foundation\System;

use Converge\Contracts\DatabaseInterface;
use mysqli;

class MysqlAdapter implements DatabaseInterface
{
    private readonly mysqli $db;

    public function __construct(
        string $host,
        string $user,
        string $password,
        string $database,
        int $port = 3306,
    ) {
        $this->db = new mysqli($host, $user, $password, $database, $port);
        if ($this->db->connect_error) {
            throw new \RuntimeException("MySQL 连接失败: {$this->db->connect_error}");
        }
        $this->db->set_charset('utf8mb4');
    }

    public function query(string $sql): mixed
    {
        $result = $this->db->query($sql);
        if ($result === false) {
            throw new \RuntimeException("SQL 错误: {$this->db->error} [{$sql}]");
        }
        return $result;
    }

    public function prepare(string $sql): mixed
    {
        return $this->db->prepare($sql);
    }

    public function escape(string $value): string
    {
        return $this->db->real_escape_string($value);
    }

    public function lastInsertId(): int
    {
        return (int) $this->db->insert_id;
    }

    public function affectedRows(): int
    {
        return $this->db->affected_rows;
    }

    public function raw(): mixed
    {
        return $this->db;
    }
}
