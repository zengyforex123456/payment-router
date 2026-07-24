<?php
/**
 * CloakDecision — 斗篷判定结果值对象
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Cloak\Domain;

final class CloakDecision
{
    public string $action;      // safe, real, block
    public string $reason;
    public ?CloakRule $matchedRule;
    public CloakVisitor $visitor;

    public function __construct(string $action, string $reason, ?CloakRule $rule, CloakVisitor $visitor)
    {
        $this->action = $action; $this->reason = $reason;
        $this->matchedRule = $rule; $this->visitor = $visitor;
    }

    /** 工厂：放行到安全页 */
    public static function safe(string $reason, ?CloakRule $rule, CloakVisitor $v): self
    { return new self('safe', $reason, $rule, $v); }

    /** 工厂：放行到真实页 */
    public static function real(string $reason, CloakVisitor $v): self
    { return new self('real', $reason, null, $v); }

    /** 工厂：拦截 */
    public static function block(string $reason, CloakVisitor $v): self
    { return new self('block', $reason, null, $v); }

    /** 默认：未知流量一律到安全页 */
    public static function defaultSafe(CloakVisitor $v): self
    { return new self('safe', 'default: unknown traffic', null, $v); }
}
