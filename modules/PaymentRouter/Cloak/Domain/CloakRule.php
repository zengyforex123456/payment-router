<?php
/**
 * CloakRule — 斗篷规则实体
 *
 * 每条规则: 检查某个字段 → 匹配条件 → 执行动作 (safe / real / block)。
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Cloak\Domain;

final class CloakRule
{
    public int $id;
    public string $field;       // ip, user_agent, country, language, referrer, is_proxy
    public string $operator;    // equals, contains, in_cidr, regex, is_empty, not_empty
    public string $value;       // 匹配值
    public string $action;      // safe, real, block
    public int $priority;       // 优先级 (数字越小越优先)
    public bool $enabled;

    public function __construct(
        int $id, string $field, string $operator, string $value,
        string $action = 'safe', int $priority = 100, bool $enabled = true,
    ) {
        $this->id = $id; $this->field = $field; $this->operator = $operator;
        $this->value = $value; $this->action = $action;
        $this->priority = $priority; $this->enabled = $enabled;
    }

    /** 判断访客是否匹配此规则 */
    public function matches(CloakVisitor $visitor): bool
    {
        $fieldValue = match ($this->field) {
            'ip'          => $visitor->ip,
            'user_agent'  => $visitor->userAgent,
            'country'     => $visitor->country,
            'language'    => $visitor->acceptLanguage,
            'referrer'    => $visitor->referrer,
            'is_proxy'    => $visitor->isProxy ? '1' : '0',
            'is_datacenter'=> $visitor->isDatacenter ? '1' : '0',
            default       => '',
        };

        return match ($this->operator) {
            'equals'    => strtolower($fieldValue) === strtolower($this->value),
            'contains'  => stripos($fieldValue, $this->value) !== false,
            'regex'     => @preg_match($this->value, $fieldValue) === 1,
            'is_empty'  => empty($fieldValue),
            'not_empty' => !empty($fieldValue),
            'in_cidr'   => $this->matchCidr($fieldValue, $this->value),
            default     => false,
        };
    }

    /** CIDR 网段匹配 */
    private function matchCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) return $ip === $cidr;
        [$subnet, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;
        $mask = -1 << (32 - (int)$bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
