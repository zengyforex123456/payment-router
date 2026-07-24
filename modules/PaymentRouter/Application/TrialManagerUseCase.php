<?php
/**
 * TrialManagerUseCase — 免费试用管理
 *
 * P1: 新租户自动获得 14 天入门版全功能试用。
 * 到期自动降级为 community 限制，保留数据。
 * 第 7/13 天推送提醒。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;

final class TrialManagerUseCase
{
    private DatabaseInterface $db;
    private int $trialDays;

    public function __construct(DatabaseInterface $db, int $trialDays = 14)
    {
        $this->db = $db;
        $this->trialDays = $trialDays;
    }

    /**
     * 为新租户开通试用。
     */
    public function startTrial(int $tenantId): array
    {
        $existing = $this->getTrialStatus($tenantId);
        if ($existing['in_trial'] ?? false) {
            return $existing;
        }

        $startedAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$this->trialDays} days"));

        // 设置租户套餐为 starter（试用期间全功能）
        $stmt = $this->db->prepare(
            'INSERT INTO payment_router_tenant_config (tenant_id, tier, strategy_name, routing_method, cooling_threshold, cooldown_minutes)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE tier=VALUES(tier)'
        );
        $tier = 'starter';
        $strategy = 'balanced';
        $method = 'weighted';
        $coolThresh = 3;
        $coolMin = 30;
        $stmt->bind_param('issiii', $tenantId, $tier, $strategy, $method, $coolThresh, $coolMin);
        $stmt->execute();

        // 记录试用
        $stmt2 = $this->db->prepare(
            'INSERT INTO payment_router_trials (tenant_id, started_at, expires_at, status)
             VALUES (?, ?, ?, ?)'
        );
        $status = 'active';
        $stmt2->bind_param('isss', $tenantId, $startedAt, $expiresAt, $status);
        $stmt2->execute();

        return [
            'trial_active' => true,
            'days_left'    => $this->trialDays,
            'expires_at'   => $expiresAt,
            'tier'         => 'starter',
        ];
    }

    /**
     * 获取试用状态。
     */
    public function getTrialStatus(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payment_router_trials WHERE tenant_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            return ['in_trial' => false, 'trials_used' => 0];
        }

        $expiresAt = strtotime($row['expires_at']);
        $daysLeft = max(0, (int)ceil(($expiresAt - time()) / 86400));
        $isActive = $row['status'] === 'active' && $expiresAt > time();

        return [
            'in_trial'    => $isActive,
            'status'      => $row['status'],
            'started_at'  => $row['started_at'],
            'expires_at'  => $row['expires_at'],
            'days_left'   => $daysLeft,
            'should_notify'=> in_array($daysLeft, [7, 13, 1], true),
        ];
    }

    /**
     * 检查并处理到期试用（Cron 每日调用）。
     * 到期 → 降级为 community + 发送通知。
     */
    public function expireTrials(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, tenant_id FROM payment_router_trials WHERE status = 'active' AND expires_at < NOW()"
        );
        $stmt->execute();
        $expired = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $downgraded = 0;
        foreach ($expired as $trial) {
            $tenantId = (int)$trial['tenant_id'];
            $trialId = (int)$trial['id'];

            // 降级为 community
            $stmt2 = $this->db->prepare(
                "UPDATE payment_router_tenant_config SET tier = 'community' WHERE tenant_id = ?"
            );
            $stmt2->bind_param('i', $tenantId);
            $stmt2->execute();

            // 标记试用过期
            $stmt3 = $this->db->prepare(
                "UPDATE payment_router_trials SET status = 'expired' WHERE id = ?"
            );
            $stmt3->bind_param('i', $trialId);
            $stmt3->execute();

            $downgraded++;
        }

        return ['expired_trials' => count($expired), 'downgraded' => $downgraded];
    }

    /**
     * 升级套餐（购买后调用）。
     */
    public function upgrade(int $tenantId, string $newTier, string $licenseKey = ''): array
    {
        $validTiers = ['starter', 'pro', 'enterprise'];
        if (!in_array($newTier, $validTiers, true)) {
            throw new \RuntimeException("无效套餐: {$newTier}");
        }

        // 更新套餐
        $stmt = $this->db->prepare("UPDATE payment_router_tenant_config SET tier = ? WHERE tenant_id = ?");
        $stmt->bind_param('si', $newTier, $tenantId);
        $stmt->execute();

        // 如果有关联 License，绑定
        if ($licenseKey !== '') {
            $stmt2 = $this->db->prepare(
                "INSERT INTO payment_router_licenses (license_key, domain, tier, issued_at, expires_at, signature, status)
                 VALUES (?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), ?, 'active')
                 ON DUPLICATE KEY UPDATE tier=VALUES(tier), expires_at=VALUES(expires_at)"
            );
            $sig = hash_hmac('sha256', "{$licenseKey}:*:{$newTier}:" . date('Y-m-d', strtotime('+1 year')), $_ENV['APP_SECRET'] ?? 'change-me');
            $stmt2->bind_param('ssss', $licenseKey, '*', $newTier, $sig);
            $stmt2->execute();
        }

        return [
            'upgraded'  => true,
            'tier'      => $newTier,
            'license'   => $licenseKey !== '' ? $licenseKey : null,
        ];
    }
}
