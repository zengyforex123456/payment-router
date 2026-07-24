<?php
/**
 * DatabaseInterface 桩 — 独立运行时最小实现
 *
 * 当不加载完整 Converge kernel 时使用。
 * 正式环境由 kernel/src/Contracts/DatabaseInterface.php 提供。
 */
declare(strict_types=1);

namespace Converge\Contracts;

interface DatabaseInterface
{
    public function query(string $sql): mixed;
    public function prepare(string $sql): mixed;
    public function escape(string $value): string;
    public function lastInsertId(): int;
    public function affectedRows(): int;
    public function raw(): mixed;
}
