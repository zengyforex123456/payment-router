<?php
/**
 * RoutingScript — 自定义路由脚本引擎
 *
 * 企业版: 允许用户编写简单的 DSL 规则。
 *
 * 支持的条件: amount_gt, amount_lte, gateway_is, weight_gte, domain_contains
 * 支持的动作: use_group, prefer_highest_weight, fallback
 *
 * DSL 示例:
 * [
 *   ['condition' => 'amount_gt:100',    'action' => 'prefer:weight_gte:5'],
 *   ['condition' => 'gateway:stripe',   'action' => 'prefer:weight_gte:3'],
 *   ['condition' => 'default',          'action' => 'round_robin'],
 * ]
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Domain;

use RuntimeException;

final class RoutingScript
{
    private array $rules;

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    /**
     * 解析并执行 DSL 规则，返回推荐的路由策略名称。
     *
     * @param array $context ['amount'=>'99.99', 'gateway'=>'paypal', ...]
     * @return array{routing_method: string, matched_rule: int, action: string}
     */
    public function evaluate(array $context): array
    {
        foreach ($this->rules as $i => $rule) {
            $condition = $rule['condition'] ?? '';
            $action = $rule['action'] ?? 'weighted';

            if ($this->matches($condition, $context)) {
                return [
                    'routing_method' => $this->resolveMethod($action),
                    'matched_rule'   => $i,
                    'action'         => $action,
                ];
            }
        }

        // Fallback to weighted
        return ['routing_method' => 'weighted', 'matched_rule' => -1, 'action' => 'weighted'];
    }

    private function matches(string $condition, array $context): bool
    {
        if ($condition === 'default' || $condition === '') return true;

        // Parse: "amount_gt:100"
        if (str_starts_with($condition, 'amount_gt:')) {
            $threshold = (float)substr($condition, 10);
            return ((float)($context['amount'] ?? 0)) > $threshold;
        }

        if (str_starts_with($condition, 'amount_lte:')) {
            $threshold = (float)substr($condition, 11);
            return ((float)($context['amount'] ?? 0)) <= $threshold;
        }

        if (str_starts_with($condition, 'gateway:')) {
            $gateway = substr($condition, 8);
            return ($context['gateway'] ?? '') === $gateway;
        }

        if (str_starts_with($condition, 'currency:')) {
            $currency = substr($condition, 9);
            return ($context['currency'] ?? 'USD') === $currency;
        }

        return false;
    }

    private function resolveMethod(string $action): string
    {
        // "prefer:weight_gte:5" → use weighted strategy
        if (str_starts_with($action, 'prefer:')) return 'weighted';
        if ($action === 'round_robin') return 'round_robin';
        if ($action === 'random') return 'random';
        return 'weighted';
    }

    /** 验证 DSL 规则集的有效性 */
    public static function validate(array $rules): array
    {
        $errors = [];
        $validConditions = ['amount_gt:', 'amount_lte:', 'gateway:', 'currency:', 'default'];
        $validActions = ['prefer:', 'round_robin', 'random', 'weighted'];

        foreach ($rules as $i => $rule) {
            $cond = $rule['condition'] ?? '';
            $action = $rule['action'] ?? '';
            $condOk = false; $actOk = false;

            foreach ($validConditions as $vc) {
                if ($cond === 'default' || str_starts_with($cond, $vc)) { $condOk = true; break; }
            }
            foreach ($validActions as $va) {
                if ($action === $va || str_starts_with($action, $va)) { $actOk = true; break; }
            }
            if (!$condOk) $errors[] = "Rule $i: invalid condition '$cond'";
            if (!$actOk) $errors[] = "Rule $i: invalid action '$action'";
        }

        return $errors;
    }

    public function toArray(): array
    {
        return $this->rules;
    }
}
