<?php

declare(strict_types=1);

namespace Converge\Security;

/**
 * DualAuth — 双通道认证
 *
 * 原则: Web 交互用 CSRF，机器间调用用 API Token。混用必死。
 *
 * 路由规则:
 *   /api/*           → Bearer Token (API Key)
 *   public/api/*.php  → Bearer Token (API Key)
 *   /health, /metrics → No auth (GET) / API Key (POST)
 *   Everything else   → Session + CSRF (Browser)
 *
 * 用法:
 *   $auth = DualAuth::resolve($db, $_SERVER);
 *   if ($auth->denied) { http_response_code(401); die($auth->reason); }
 */
class DualAuth
{
    private \mysqli $db;
    private array $server;

    public function __construct(\mysqli $db, array $server = [])
    {
        $this->db = $db;
        $this->server = $server ?: $_SERVER;
    }

    /**
     * Resolve the appropriate auth method for this request.
     */
    public function resolve(): AuthResult
    {
        $uri = $this->server['REQUEST_URI'] ?? '';
        $method = $this->server['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($uri, PHP_URL_PATH) ?? $uri;

        // ── API routes: Bearer Token only ──
        if ($this->isApiRoute($path)) {
            return $this->checkApiKey();
        }

        // ── Health/metrics: public GET, token POST ──
        if ($this->isPublicRoute($path)) {
            if ($method === 'GET') {
                return AuthResult::allow('public');
            }
            return $this->checkApiKey();
        }

        // ── Browser routes: Session + CSRF ──
        return $this->checkSession();
    }

    /**
     * Generate an API key for a user (runs once, used by cron/scripts).
     */
    public function generateApiKey(int $userId, string $name = 'default'): string
    {
        $token = 'ck_' . bin2hex(random_bytes(24)); // ck_ prefix, 48 chars
        $tokenHash = hash('sha256', $token);

        $stmt = $this->db->prepare(
            "INSERT INTO api_keys (user_id, name, token_hash, created_at) VALUES (?, ?, ?, NOW())"
        );
        $stmt->bind_param('iss', $userId, $name, $tokenHash);
        $stmt->execute();
        $stmt->close();

        return $token;
    }

    /**
     * List API keys for a user (tokens never shown, only metadata).
     */
    public function listApiKeys(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, created_at, last_used_at FROM api_keys WHERE user_id = ? AND revoked_at IS NULL"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $keys = [];
        while ($row = $result->fetch_assoc()) {
            $keys[] = $row;
        }
        return $keys;
    }

    // ═══════════════════════════════════════
    // Internal
    // ═══════════════════════════════════════

    private function isApiRoute(string $path): bool
    {
        return str_starts_with($path, '/api/')
            || str_contains($path, '/api-');
    }

    private function isPublicRoute(string $path): bool
    {
        $public = ['/health', '/metrics', '/ping', '/test.php'];
        return in_array($path, $public);
    }

    private function checkApiKey(): AuthResult
    {
        $header = $this->server['HTTP_AUTHORIZATION'] ?? '';
        $token = '';

        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
        }

        if (empty($token)) {
            return AuthResult::deny('Missing Bearer token. Generate at Settings → API Keys.');
        }

        $tokenHash = hash('sha256', $token);

        $stmt = $this->db->prepare(
            "SELECT ak.id, ak.user_id, ak.name
             FROM api_keys ak
             WHERE ak.token_hash = ? AND ak.revoked_at IS NULL
             LIMIT 1"
        );
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return AuthResult::deny('Invalid or revoked API key.');
        }

        // Update last_used_at
        $stmt = $this->db->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $row['id']);
        $stmt->execute();
        $stmt->close();

        return AuthResult::allow('api_key', [
            'user_id' => (int)$row['user_id'],
            'api_key_id' => (int)$row['id'],
            'key_name' => $row['name'],
        ]);
    }

    private function checkSession(): AuthResult
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            return AuthResult::deny('Login required.');
        }

        // CSRF check for POST/PUT/DELETE
        $method = $this->server['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            $token = $_POST['app_csrf'] ?? $this->server['HTTP_X_CSRF_TOKEN'] ?? '';
            if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
                return AuthResult::deny('CSRF validation failed. Refresh the page and try again.');
            }
        }

        return AuthResult::allow('session', [
            'user_id' => (int)$_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
        ]);
    }
}

class AuthResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $method,
        public readonly string $reason,
        public readonly array $context,
    ) {}

    public static function allow(string $method, array $context = []): self
    {
        return new self(true, $method, '', $context);
    }

    public static function deny(string $reason): self
    {
        return new self(false, 'none', $reason, []);
    }

    public function isApiKey(): bool { return $this->method === 'api_key'; }
    public function isSession(): bool { return $this->method === 'session'; }
}
