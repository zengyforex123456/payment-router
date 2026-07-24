<?php
/**
 * LicenseManagerUseCase — 授权证书管理
 *
 * 签发/验证/吊销 License，管理更新订阅。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;
use Converge\Modules\PaymentRouter\Domain\License;

final class LicenseManagerUseCase
{
    private DatabaseInterface $db;
    private string $signingKey;

    public function __construct(DatabaseInterface $db, string $signingKey = '')
    {
        $this->db = $db;
        $this->signingKey = $signingKey !== '' ? $signingKey : ($_ENV['APP_SECRET'] ?? 'change-me');
    }

    /** 签发新 License */
    public function issue(string $domain, string $tier = 'pro', string $duration = '+1 year'): License
    {
        $license = new License(License::generateKey(), $domain, $tier, date('Y-m-d'), date('Y-m-d', strtotime($duration)));
        $signed = $license->sign($this->signingKey);

        $stmt = $this->db->prepare(
            'INSERT INTO payment_router_licenses (license_key, domain, tier, issued_at, expires_at, signature, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $status = 'active';
        $stmt->bind_param('sssssss',
            $signed->licenseKey, $signed->domain, $signed->tier,
            $signed->issuedAt, $signed->expiresAt, $signed->signature, $status
        );
        $stmt->execute();

        return $signed;
    }

    /** 验证 License（含离线缓存） */
    public function validate(string $licenseKey, string $domain): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payment_router_licenses WHERE license_key = ? AND status = ?'
        );
        $status = 'active';
        $stmt->bind_param('ss', $licenseKey, $status);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            // 尝试离线缓存验证
            return $this->offlineValidate($licenseKey, $domain);
        }

        $license = new License(
            $row['license_key'], $row['domain'], $row['tier'],
            $row['issued_at'], $row['expires_at'], $row['signature']
        );

        if ($license->domain !== $domain && $license->domain !== '*') {
            return ['valid' => false, 'reason' => 'domain_mismatch', 'expected' => $license->domain, 'actual' => $domain];
        }

        if ($license->isExpired() && !$license->isInGracePeriod()) {
            return ['valid' => false, 'reason' => 'expired', 'expired_at' => $license->expiresAt];
        }

        if (!$license->verify($this->signingKey)) {
            return ['valid' => false, 'reason' => 'signature_invalid'];
        }

        return [
            'valid'       => true,
            'tier'        => $license->tier,
            'domain'      => $license->domain,
            'expires_at'  => $license->expiresAt,
            'days_left'   => $license->daysUntilExpiry(),
            'grace_period'=> $license->isInGracePeriod(),
        ];
    }

    /** 吊销 License */
    public function revoke(string $licenseKey): void
    {
        $stmt = $this->db->prepare("UPDATE payment_router_licenses SET status = 'revoked' WHERE license_key = ?");
        $stmt->bind_param('s', $licenseKey);
        $stmt->execute();
    }

    /** 列出所有 License */
    public function listAll(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_router_licenses ORDER BY issued_at DESC LIMIT 100');
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** 离线验证（缓存 License 到本地文件） */
    private function offlineValidate(string $licenseKey, string $domain): array
    {
        $cacheFile = sys_get_temp_dir() . '/pr_license_' . md5($licenseKey) . '.json';
        if (!file_exists($cacheFile)) {
            return ['valid' => false, 'reason' => 'license_not_found'];
        }

        $cached = json_decode(file_get_contents($cacheFile), true);
        if (!$cached) return ['valid' => false, 'reason' => 'cache_corrupt'];

        $license = new License(
            $cached['license_key'], $cached['domain'], $cached['tier'],
            $cached['issued_at'], $cached['expires_at'], $cached['signature']
        );

        // 缓存有效期为 24 小时
        if (time() - ($cached['cached_at'] ?? 0) > 86400) {
            return ['valid' => false, 'reason' => 'cache_expired'];
        }

        if (!$license->isValid() || !$license->verify($this->signingKey)) {
            return ['valid' => false, 'reason' => 'offline_invalid'];
        }

        return [
            'valid'       => true,
            'tier'        => $license->tier,
            'offline'     => true,
            'expires_at'  => $license->expiresAt,
            'days_left'   => $license->daysUntilExpiry(),
        ];
    }

    /** 缓存 License 到本地（离线验证用） */
    public function cacheOffline(License $license): void
    {
        $cacheFile = sys_get_temp_dir() . '/pr_license_' . md5($license->licenseKey) . '.json';
        $data = $license->toArray();
        $data['cached_at'] = time();
        file_put_contents($cacheFile, json_encode($data));
    }
}
