<?php
/** RememberTokenService — Remember Me Token 生命周期管理 (从 Auth 提取) */
declare(strict_types=1);

namespace Converge\Security\Auth;

use mysqli;

final class RememberTokenService
{
    public const TOKEN_LIFETIME = 2592000; // 30 days
    private const TOKEN_BYTES = 32;

    public function __construct(private readonly mysqli $db) {}

    /** Create and persist a remember token, set cookie */
    public function create(int $userId): string
    {
        $this->deleteTokensForUser($userId);

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $selector = bin2hex(random_bytes(16));
        $hashedToken = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + self::TOKEN_LIFETIME);

        $stmt = $this->db->prepare(
            "INSERT INTO remember_tokens (user_id, selector, hashed_token, expires_at, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('isss', $userId, $selector, $hashedToken, $expires);
        $stmt->execute();
        $stmt->close();

        $this->setCookie("{$selector}:{$token}");
        return $token;
    }

    /** Validate remember token from cookie, return user_id or 0 */
    public function validate(string $cookieValue): int
    {
        if (!$this->tableExists()) return 0;

        $parts = explode(':', $cookieValue, 2);
        if (count($parts) !== 2) return 0;
        [$selector, $token] = $parts;

        $hashedToken = hash('sha256', $token);
        $stmt = $this->db->prepare(
            "SELECT user_id, expires_at FROM remember_tokens
             WHERE selector = ? AND hashed_token = ? LIMIT 1"
        );
        $stmt->bind_param('ss', $selector, $hashedToken);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) return 0;
        if (strtotime($row['expires_at']) < time()) {
            $this->clearBySelector($selector);
            return 0;
        }
        return (int)$row['user_id'];
    }

    /** Clear token from DB and cookie */
    public function clear(string $cookieValue): void
    {
        $parts = explode(':', $cookieValue, 2);
        if (count($parts) === 2) {
            $this->clearBySelector($parts[0]);
        }
        $this->removeCookie();
    }

    private function clearBySelector(string $selector): void
    {
        if (!$this->tableExists()) return;
        $stmt = $this->db->prepare("DELETE FROM remember_tokens WHERE selector = ?");
        $stmt->bind_param('s', $selector);
        $stmt->execute();
        $stmt->close();
    }

    /** Delete all remember tokens for a user */
    private function deleteTokensForUser(int $userId): void
    {
        if (!$this->tableExists()) return;
        $stmt = $this->db->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function tableExists(): bool
    {
        $r = $this->db->query("SHOW TABLES LIKE 'remember_tokens'");
        return $r && $r->num_rows > 0;
    }

    private function setCookie(string $value): void
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        setcookie('remember_token', $value, [
            'expires' => time() + self::TOKEN_LIFETIME,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function removeCookie(): void
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        setcookie('remember_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
