<?php
/** SessionManager — 安全会话生命周期管理 (从 Auth 提取) */
declare(strict_types=1);

namespace Converge\Security\Auth;

final class SessionManager
{
    public const SESSION_LIFETIME = 7200; // 2 hours

    /** Initialize secure session with cookie hardening */
    public static function init(int $lifetime = self::SESSION_LIFETIME): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /** Destroy session and clear cookie */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    /** Regenerate session ID (call before writing auth data) */
    public static function rotate(): void
    {
        session_regenerate_id(true);
    }

    /** Check if session is still within lifetime */
    public static function isExpired(int $loginTime, int $lifetime = self::SESSION_LIFETIME): bool
    {
        return (time() - $loginTime) >= $lifetime;
    }
}
