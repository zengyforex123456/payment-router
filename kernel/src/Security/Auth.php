<?php

declare(strict_types=1);

namespace Converge\Security;

use mysqli;
use Converge\Security\Auth\SessionManager;
use Converge\Security\Auth\RateLimiter;
use Converge\Security\Auth\RememberTokenService;

/**
 * Authentication System — Thin orchestrator
 * Delegates to: SessionManager, RateLimiter, RememberTokenService
 */
class Auth
{
    private mysqli $db;
    private RateLimiter $rateLimiter;
    private RememberTokenService $rememberToken;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->rateLimiter = new RateLimiter($db);
        $this->rememberToken = new RememberTokenService($db);
        SessionManager::init();
    }

    /** 确保连接存活——控制器可能已关闭共享连接。Ping 失败(含 mock)时保持已有连接。 */
    private function db(): mysqli
    {
        try {
            if (!@$this->db->ping()) {
                throw new \RuntimeException('Connection lost');
            }
        } catch (\Throwable) {
            // ping() may not exist on mock — keep existing $this->db
            try {
                $this->db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            } catch (\Throwable) {
                // Can't reconnect — return existing (likely mock)
            }
        }
        return $this->db;
    }

    /** Legacy installs with no roles: full access only outside production. */
    public static function allowsLegacyNoRolesFallback(): bool
    {
        if (defined('INSTALLER_DEV_MODE') && INSTALLER_DEV_MODE) return true;
        if (defined('APP_ENV') && APP_ENV !== 'production') return true;
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        return $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.test');
    }

    // ═══ Login / Logout ═══

    /** Login user with rate limiting and remember-me support */
    public function login(string $username, string $password, bool $remember = false): array
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (defined('LOGIN_RATE_LIMIT_ENABLED') && LOGIN_RATE_LIMIT_ENABLED) {
            if ($this->rateLimiter->isIpRateLimited($ipAddress)) {
                return ['success' => false, 'message' => 'Too many login attempts from your IP. Please wait and try again.'];
            }
            $accountStatus = $this->rateLimiter->isAccountLocked($username);
            if ($accountStatus['locked']) {
                return ['success' => false, 'message' => "Account temporarily locked after {$accountStatus['failures']} failed attempts. Please wait {$accountStatus['wait_minutes']} minutes or reset your password."];
            }
        }

        $stmt = $this->db()->prepare(
            "SELECT id, username, pass_hash, email FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1"
        );
        $stmt->bind_param('ss', $username, $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            $this->rateLimiter->recordFailure($ipAddress, $username);
            return ['success' => false, 'message' => 'Invalid username or password'];
        }

        if (!password_verify($password, $user['pass_hash'])) {
            $this->rateLimiter->recordFailure($ipAddress, $username);
            return ['success' => false, 'message' => 'Invalid username or password'];
        }

        SessionManager::rotate();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['plan'] = 'free';
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();

        $this->loadUserRolesIntoSession($user['id']);
        $this->updateLastLogin($user['id']);

        (new \Converge\Security\AuditLogger($this->db))->logLogin($user['id']);

        if ($remember) {
            $this->rememberToken->create($user['id']);
        }

        return ['success' => true, 'message' => 'Login successful'];
    }

    /** Logout user */
    public function logout(): void
    {
        if (!empty($_SESSION['user_id'])) {
            (new \Converge\Security\AuditLogger($this->db))->logLogout($_SESSION['user_id']);
        }

        if (isset($_COOKIE['remember_token'])) {
            $this->rememberToken->clear($_COOKIE['remember_token']);
        }

        SessionManager::destroy();
    }

    // ═══ Auth Checks ═══

    public function isAuthenticated(): bool
    {
        if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
            if (!SessionManager::isExpired((int)($_SESSION['login_time'] ?? 0))) {
                return true;
            }
        }
        if (isset($_COOKIE['remember_token'])) {
            return $this->loginFromRememberToken();
        }
        return false;
    }

    /** Require authentication (middleware) — redirects if not authenticated */
    public function requireAuth(): void
    {
        AdminGate::enforce($this->db);
        if ($this->isAuthenticated()) return;

        $loginUrl = defined('APP_BASE_URL') ? APP_BASE_URL . '/login-v2.php' : 'login-v2.php';

        if ($this->clientExpectsJson()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow', true);
            echo json_encode(['error' => 'Unauthorized', 'login_url' => $loginUrl]);
            exit;
        }

        header('Location: ' . $loginUrl);
        exit;
    }

    public function getPermission(): ?Permission
    {
        return $this->isAuthenticated() ? new Permission($this->db, $_SESSION['user_id']) : null;
    }

    // ═══ Password Management ═══

    public function verifyPasswordForUser(int $userId, string $plainPassword): bool
    {
        $stmt = $this->db()->prepare('SELECT pass_hash FROM users WHERE id = ? AND is_active = 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row && !empty($row['pass_hash']) && password_verify($plainPassword, $row['pass_hash']);
    }

    /** @return array{success: bool, message?: string, errors?: array<string, string>} */
    public function changePassword(int $userId, string $currentPassword, string $newPassword, string $confirmPassword): array
    {
        $errors = [];
        if ($currentPassword === '') $errors['current_password'] = 'Current password is required';
        elseif (!$this->verifyPasswordForUser($userId, $currentPassword)) $errors['current_password'] = 'Current password is incorrect';
        if ($newPassword === '') $errors['new_password'] = 'New password is required';
        elseif (strlen($newPassword) < 8) $errors['new_password'] = 'Password must be at least 8 characters';
        if ($newPassword !== $confirmPassword) $errors['confirm_password'] = 'Passwords do not match';
        if ($newPassword !== '' && $currentPassword !== '' && $newPassword === $currentPassword) $errors['new_password'] = 'New password must be different from your current password';
        if ($errors !== []) return ['success' => false, 'errors' => $errors];

        $stmt = $this->db()->prepare('UPDATE users SET pass_hash = ?, updated_at = NOW() WHERE id = ? AND is_active = 1');
        $pwHash = password_hash($newPassword, HASH_ALGO, HASH_OPTIONS);
        $stmt->bind_param('si', $pwHash, $userId);
        if (!$stmt->execute()) return ['success' => false, 'errors' => ['general' => 'Failed to update password']];

        $this->rememberToken->clear($_COOKIE['remember_token'] ?? '');
        return ['success' => true, 'message' => 'Password changed successfully'];
    }

    // ═══ Current User ═══

    public function getCurrentUser(): ?array
    {
        if (!$this->isAuthenticated()) return null;
        $columns = 'id, username, email, timezone, currency, role_id, is_active';
        $themeCol = $this->db()->query("SHOW COLUMNS FROM users LIKE 'theme'");
        if ($themeCol && $themeCol->num_rows > 0) $columns = 'id, username, email, timezone, currency, theme, role_id, is_active';

        $stmt = $this->db()->prepare("SELECT {$columns} FROM users WHERE id = ?");
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if ($user) {
            $user['role_ids'] = $_SESSION['role_ids'] ?? [];
            $user['role_names'] = $_SESSION['role_names'] ?? [];
        }
        return $user;
    }

    // ═══ Password Reset (kept inline for transactional integrity) ═══

    public function requestPasswordReset(string $email): array
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($this->rateLimiter->getRecentResetRequests($ipAddress) >= $this->rateLimiter->getMaxResetRequestsPerIp()) {
            return ['success' => false, 'message' => 'Too many reset requests. Please wait before trying again.'];
        }

        $stmt = $this->db()->prepare("SELECT id, username, email FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) return ['success' => true, 'message' => 'If an account exists with that email, a password reset link has been sent.'];

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $this->invalidateUserTokens($user['id']);

        $stmt = $this->db()->prepare("INSERT INTO password_reset_tokens (user_id, token, token_plain, expires_at, ip_address) VALUES (?, ?, '', ?, ?)");
        $stmt->bind_param('isss', $user['id'], $tokenHash, $expiresAt, $ipAddress);

        if (!$stmt->execute()) return ['success' => false, 'message' => 'Failed to generate reset token. Please try again.'];

        return ['success' => true, 'message' => 'If an account exists with that email, a password reset link has been sent.', 'token' => $token, 'user' => $user];
    }

    public function validateResetToken(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);
        $stmt = $this->db()->prepare("SELECT prt.id, prt.user_id, prt.expires_at, prt.used_at, u.email, u.username FROM password_reset_tokens prt INNER JOIN users u ON prt.user_id = u.id WHERE (prt.token = ? OR prt.token_plain = ?) AND prt.expires_at > ? AND prt.used_at IS NULL LIMIT 1");
        $now = date('Y-m-d H:i:s');
        $stmt->bind_param('sss', $tokenHash, $token, $now);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if (!$data) return null;
        return ['token_id' => $data['id'], 'user_id' => $data['user_id'], 'email' => $data['email'], 'username' => $data['username']];
    }

    public function resetPassword(string $token, string $newPassword): array
    {
        $tokenData = $this->validateResetToken($token);
        if (!$tokenData) return ['success' => false, 'message' => 'Invalid or expired reset token.'];
        if (strlen($newPassword) < 8) return ['success' => false, 'message' => 'Password must be at least 8 characters long.'];

        $pwHash = password_hash($newPassword, HASH_ALGO, HASH_OPTIONS);
        $this->db()->begin_transaction();
        try {
            $stmt = $this->db()->prepare("UPDATE users SET pass_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('si', $pwHash, $tokenData['user_id']);
            if (!$stmt->execute()) throw new \Exception('Failed to update password');

            $stmt = $this->db()->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $tokenData['token_id']);
            $stmt->execute();

            $this->invalidateUserTokens($tokenData['user_id']);
            $this->db()->commit();
            return ['success' => true, 'message' => 'Password reset successfully. You can now login with your new password.'];
        } catch (\Exception $e) {
            $this->db()->rollback();
            return ['success' => false, 'message' => 'Failed to reset password. Please try again.'];
        }
    }

    // ═══ Private Helpers ═══

    private function loginFromRememberToken(): bool
    {
        if (empty($_COOKIE['remember_token'])) return false;
        $userId = $this->rememberToken->validate($_COOKIE['remember_token']);
        if ($userId <= 0) return false;

        $stmt = $this->db()->prepare("SELECT id, username, email FROM users WHERE id = ? AND is_active = 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user) return false;

        SessionManager::rotate();
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();

        $this->loadUserRolesIntoSession((int)$user['id']);
        $this->rememberToken->create((int)$user['id']);
        return true;
    }

    private function loadUserRolesIntoSession(int $userId): void
    {
        $roleIds = []; $roleNames = [];
        $stmt = $this->db()->prepare("SELECT r.id, r.name FROM users u INNER JOIN roles r ON u.role_id = r.id WHERE u.id = ? AND u.is_active = 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        if ($row) { $roleIds[] = $row['id']; $roleNames[] = $row['name']; }

        $stmt = $this->db()->prepare("SELECT r.id, r.name FROM user_roles ur INNER JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!in_array($row['id'], $roleIds)) { $roleIds[] = $row['id']; $roleNames[] = $row['name']; }
        }
        $stmt->close();

        if (SingleAdminMode::isEnabled()) {
            SingleAdminMode::ensureAdminRoleForUser($this->db, $userId);
            [$roleIds, $roleNames] = SingleAdminMode::adminSessionRoles($this->db);
        }

        $_SESSION['role_ids'] = $roleIds;
        $_SESSION['role_names'] = $roleNames;
    }

    private function clientExpectsJson(): bool
    {
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json')
            || strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0;
    }

    private function updateLastLogin(int $userId): void
    {
        $stmt = $this->db()->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }

    private function invalidateUserTokens(int $userId): void
    {
        $stmt = $this->db()->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }
}
