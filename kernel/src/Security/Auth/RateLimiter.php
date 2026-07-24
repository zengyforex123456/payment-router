<?php
/** RateLimiter — 登录速率限制 + 账户锁定 + 失败跟踪 (从 Auth 提取) */
declare(strict_types=1);

namespace Converge\Security\Auth;

use mysqli;

final class RateLimiter
{
    private const MAX_FAILURES = 5;
    private const IP_BLOCK_MINUTES = 15;
    private const ACCOUNT_LOCKOUT_FAILURES = 10;
    private const ACCOUNT_LOCKOUT_MINUTES = 30;
    private const RESET_IP_LIMIT = 3; // max reset requests per IP per hour

    public function __construct(private readonly mysqli $db) {}

    /** Check if this IP has too many recent login failures */
    public function isIpRateLimited(string $ipAddress): bool
    {
        $count = $this->countRecentFailedLogins($ipAddress);
        if ($count >= self::MAX_FAILURES) {
            $this->recordRateLimitTrigger($ipAddress, $count, self::IP_BLOCK_MINUTES . ' min');
            return true;
        }
        return false;
    }

    /** Check if account is locked out due to too many failed attempts */
    public function isAccountLocked(string $username): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as failures, MAX(created_at) as last_failure
             FROM login_attempts WHERE username = ? AND success = 0
             AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $lockMins = self::ACCOUNT_LOCKOUT_MINUTES;
        $stmt->bind_param('si', $username, $lockMins);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $failures = (int)($row['failures'] ?? 0);

        if ($failures >= self::ACCOUNT_LOCKOUT_FAILURES) {
            $lastFailure = strtotime($row['last_failure'] ?? 'now');
            $unlockTime = $lastFailure + (self::ACCOUNT_LOCKOUT_MINUTES * 60);
            $waitMinutes = (int)ceil(($unlockTime - time()) / 60);
            return ['locked' => true, 'failures' => $failures, 'wait_minutes' => max(1, $waitMinutes)];
        }
        return ['locked' => false, 'failures' => $failures, 'wait_minutes' => 0];
    }

    /** Record a failed login attempt */
    public function recordFailure(string $ipAddress, string $username): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO login_attempts (ip_address, username, success, created_at) VALUES (?, ?, 0, NOW())"
        );
        $stmt->bind_param('ss', $ipAddress, $username);
        $stmt->execute();
        $stmt->close();
    }

    /** Count recent failed attempts from this IP */
    private function countRecentFailedLogins(string $ipAddress): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as c FROM login_attempts
             WHERE ip_address = ? AND success = 0
             AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $mins = self::IP_BLOCK_MINUTES;
        $stmt->bind_param('si', $ipAddress, $mins);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        return (int)($r['c'] ?? 0);
    }

    /** Log rate limit trigger for audit trail */
    private function recordRateLimitTrigger(string $ipAddress, int $count, string $blockDuration): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO login_attempts (ip_address, username, success, created_at)
             VALUES (?, CONCAT('RATE_LIMIT:', ?), 0, NOW())"
        );
        $detail = "{$count} fails, blocked {$blockDuration}";
        $stmt->bind_param('ss', $ipAddress, $detail);
        $stmt->execute();
        $stmt->close();
    }

    /** Check recent password reset requests from IP */
    public function getRecentResetRequests(string $ipAddress): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as c FROM password_resets
             WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->bind_param('s', $ipAddress);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        return (int)($r['c'] ?? 0);
    }

    public function getMaxResetRequestsPerIp(): int
    {
        return self::RESET_IP_LIMIT;
    }
}
