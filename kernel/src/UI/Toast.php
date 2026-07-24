<?php
declare(strict_types=1);

namespace Converge\UI;

/**
 * Toast — PHP 端触发通知
 *
 * 写入 session flash → v2.php 渲染为 JS → ConvergeToast.show()
 *
 * 用法:
 *   \Converge\UI\Toast::success('Campaign created');
 *   \Converge\UI\Toast::error('Failed to delete');
 *   header('Location: /index.php?page=campaigns');
 *
 * 约定: 所有 CRUD 操作成功后调用 Toast，替代无反馈的静默成功
 */
class Toast
{
    private const SESSION_KEY = '__toasts';
    private const MAX_TOASTS = 5;
    private const DEFAULT_DURATION = 4000;

    /**
     * 添加一条 toast 到 session flash
     */
    public static function add(string $message, string $type = 'info', int $duration = self::DEFAULT_DURATION): void
    {
        if (\session_status() !== PHP_SESSION_ACTIVE) {
            return; // 不在 session 上下文，跳过 (CLI / 测试)
        }

        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        // 防止重复 (相同 type+message 在上一条 → skip)
        $last = \end($_SESSION[self::SESSION_KEY]);
        if ($last && ($last['message'] ?? '') === $message && ($last['type'] ?? '') === $type) {
            return;
        }

        $_SESSION[self::SESSION_KEY][] = [
            'message'  => $message,
            'type'     => $type,
            'duration' => $duration,
        ];

        // 限制数量
        if (\count($_SESSION[self::SESSION_KEY]) > self::MAX_TOASTS) {
            \array_shift($_SESSION[self::SESSION_KEY]);
        }
    }

    /**
     * 读取并清除所有待显示的 toast (供 v2.php 渲染)
     *
     * @return array<int, array{message:string, type:string, duration:int}>
     */
    public static function flush(): array
    {
        if (\session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION[self::SESSION_KEY])) {
            return [];
        }

        $toasts = $_SESSION[self::SESSION_KEY];
        unset($_SESSION[self::SESSION_KEY]);

        return $toasts;
    }

    // ── 快捷方法 ──

    public static function success(string $message, int $duration = self::DEFAULT_DURATION): void
    {
        self::add($message, 'success', $duration);
    }

    public static function error(string $message, int $duration = 6000): void
    {
        self::add($message, 'error', $duration);
    }

    public static function warning(string $message, int $duration = self::DEFAULT_DURATION): void
    {
        self::add($message, 'warning', $duration);
    }

    public static function info(string $message, int $duration = self::DEFAULT_DURATION): void
    {
        self::add($message, 'info', $duration);
    }
}
