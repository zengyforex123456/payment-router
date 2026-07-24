<?php
/**
 * MysqlCloakRuleRepository — 斗篷规则 MySQL 仓储
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Cloak\Infrastructure;

use Converge\Contracts\DatabaseInterface;
use Converge\Modules\PaymentRouter\Cloak\Domain\CloakRule;

final class MysqlCloakRuleRepository
{
    private DatabaseInterface $db;
    public function __construct(DatabaseInterface $db) { $this->db = $db; }

    /** 获取所有启用的规则，按优先级排序 */
    public function findAllEnabled(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_router_cloak_rules WHERE enabled = 1 ORDER BY priority');
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return array_map(fn($r) => new CloakRule(
            (int)$r['id'], $r['field'], $r['operator'], $r['value'],
            $r['action'], (int)$r['priority'], (bool)$r['enabled']
        ), $rows);
    }

    /** 查询所有规则（含禁用） */
    public function findAll(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_router_cloak_rules ORDER BY priority');
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return array_map(fn($r) => new CloakRule(
            (int)$r['id'], $r['field'], $r['operator'], $r['value'],
            $r['action'], (int)$r['priority'], (bool)$r['enabled']
        ), $rows);
    }

    /** 保存规则 */
    public function save(CloakRule $rule): void
    {
        if ($rule->id > 0) {
            $stmt = $this->db->prepare('UPDATE payment_router_cloak_rules SET field=?, operator=?, value=?, action=?, priority=?, enabled=? WHERE id=?');
            $en = (int)$rule->enabled;
            $stmt->bind_param('ssssiii', $rule->field, $rule->operator, $rule->value, $rule->action, $rule->priority, $en, $rule->id);
        } else {
            $stmt = $this->db->prepare('INSERT INTO payment_router_cloak_rules (field, operator, value, action, priority, enabled) VALUES (?, ?, ?, ?, ?, ?)');
            $en = (int)$rule->enabled;
            $stmt->bind_param('ssssii', $rule->field, $rule->operator, $rule->value, $rule->action, $rule->priority, $en);
        }
        $stmt->execute();
    }
}
