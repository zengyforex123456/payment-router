<?php
/**
 * AuthUseCase — 注册/登录/密码重置
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;

final class AuthUseCase
{
    private DatabaseInterface $db;
    public function __construct(DatabaseInterface $db) { $this->db = $db; }

    /** 注册 */
    public function register(string $email, string $password): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('邮箱格式无效');
        if (strlen($password) < 8) throw new \RuntimeException('密码至少 8 位');

        $stmt = $this->db->prepare('SELECT id FROM payment_router_users WHERE email = ?');
        $stmt->bind_param('s', $email); $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) throw new \RuntimeException('该邮箱已注册');

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $stmt2 = $this->db->prepare('INSERT INTO payment_router_users (email, pass_hash, tier) VALUES (?, ?, ?)');
        $tier = 'community';
        $stmt2->bind_param('sss', $email, $hash, $tier); $stmt2->execute();
        $userId = $this->db->lastInsertId();

        // 同步创建 tenant_config
        $stmt3 = $this->db->prepare('INSERT IGNORE INTO payment_router_tenant_config (tenant_id, tier) VALUES (?, ?)');
        $stmt3->bind_param('is', $userId, $tier); $stmt3->execute();

        // 自动开启试用
        $trialMgr = new TrialManagerUseCase($this->db, 14);
        $trialMgr->startTrial($userId);

        return ['user_id' => $userId, 'email' => $email, 'tier' => $tier, 'trial_active' => true];
    }

    /** 登录 */
    public function login(string $email, string $password): array
    {
        $stmt = $this->db->prepare('SELECT id, email, pass_hash, tier FROM payment_router_users WHERE email = ?');
        $stmt->bind_param('s', $email); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || !password_verify($password, $row['pass_hash'])) {
            throw new \RuntimeException('邮箱或密码错误');
        }
        return ['user_id' => (int)$row['id'], 'email' => $row['email'], 'tier' => $row['tier']];
    }

    /** 获取用户信息 */
    public function profile(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, email, tier, created_at FROM payment_router_users WHERE id = ?');
        $stmt->bind_param('i', $userId); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? ['user_id'=>(int)$row['id'],'email'=>$row['email'],'tier'=>$row['tier'],'created_at'=>$row['created_at']] : null;
    }
}
