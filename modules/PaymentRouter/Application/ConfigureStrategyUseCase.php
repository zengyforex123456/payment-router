<?php
/**
 * ConfigureStrategyUseCase — 配置租户策略
 *
 * SaaS: 选择预设模板 (balanced/weight_priority/safe_mode/high_volume)
 * Pro: 自定义参数
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;
use Converge\Modules\PaymentRouter\Domain\StrategyTemplate;

final class ConfigureStrategyUseCase
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * @param string $preset 预设名称 或 'custom'
     */
    public function applyPreset(int $tenantId, string $preset): array
    {
        $template = StrategyTemplate::find($preset);
        if (!$template) {
            throw new \RuntimeException("未知策略模板: {$preset}。可用: balanced, weight_priority, safe_mode, high_volume");
        }

        $this->upsert($tenantId, $template->toArray());

        return $template->toArray();
    }

    /** 自定义策略 (专业版+) */
    public function custom(int $tenantId, array $config): array
    {
        $defaults = StrategyTemplate::balanced()->toArray();
        $merged = array_merge($defaults, $config, ['name' => 'custom']);

        $this->upsert($tenantId, $merged);

        return $merged;
    }

    /** 导出租户配置为 JSON（用于 SaaS→Pro 迁移） */
    public function export(int $tenantId): array
    {
        return [
            'strategy'     => $this->get($tenantId),
            'a_sites'      => $this->getASites($tenantId),
            'b_sites'      => $this->getBSites($tenantId),
            'exported_at'  => date('c'),
            'version'      => '0.1.0',
        ];
    }

    /** 导入租户配置（从 JSON 恢复） */
    public function import(int $tenantId, array $data): array
    {
        $imported = ['strategy' => false, 'b_sites' => 0];

        // 恢复策略
        if (isset($data['strategy'])) {
            $this->custom($tenantId, $data['strategy']);
            $imported['strategy'] = true;
        }

        // 恢复 B 站配置（A 站因 API Key 安全原因不自动导入）
        if (isset($data['b_sites'])) {
            foreach ($data['b_sites'] as $bs) {
                $stmt = $this->db->prepare(
                    'INSERT INTO payment_router_b_sites (tenant_id, domain, payment_gateway, weight, max_daily_orders, status)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('issiis',
                    $tenantId, $bs['domain'], $bs['payment_gateway'],
                    $bs['weight'], $bs['max_daily_orders'], $bs['status']
                );
                $stmt->execute();
                $imported['b_sites']++;
            }
        }

        return $imported;
    }

    /** 获取当前策略 */
    public function get(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_router_tenant_config WHERE tenant_id = ?');
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            // 返回默认值
            return StrategyTemplate::balanced()->toArray();
        }

        return [
            'tier'              => $row['tier'],
            'strategy_name'     => $row['strategy_name'],
            'routing_method'    => $row['routing_method'],
            'cooling_threshold' => (int)$row['cooling_threshold'],
            'cooldown_minutes'  => (int)$row['cooldown_minutes'],
        ];
    }

    private function getASites(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT domain, platform, status FROM payment_router_a_sites WHERE tenant_id = ?');
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getBSites(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT domain, payment_gateway, weight, max_daily_orders, status FROM payment_router_b_sites WHERE tenant_id = ?');
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function upsert(int $tenantId, array $config): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO payment_router_tenant_config
             (tenant_id, tier, strategy_name, routing_method, cooling_threshold, cooldown_minutes)
             VALUES (?, \'starter\', ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
             strategy_name=VALUES(strategy_name), routing_method=VALUES(routing_method),
             cooling_threshold=VALUES(cooling_threshold), cooldown_minutes=VALUES(cooldown_minutes)'
        );
        $stmt->bind_param(
            'issii',
            $tenantId,
            $config['name'],
            $config['routing_method'],
            $config['cooling_threshold'],
            $config['cooldown_minutes']
        );
        $stmt->execute();
    }
}
