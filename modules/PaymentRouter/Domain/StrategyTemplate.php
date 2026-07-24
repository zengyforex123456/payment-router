<?php
/**
 * StrategyTemplate — 预设策略模板值对象
 *
 * 入门版 SaaS: 用户无需手动配置各项参数，选择一个模板即可。
 * 专业版/企业版: 可在此基础上自定义。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Domain;

final class StrategyTemplate
{
    public string $name;
    public string $routingMethod;
    public int $coolingThreshold;
    public int $cooldownMinutes;
    public int $defaultWeight;
    public int $defaultMaxDaily;

    private function __construct(
        string $name, string $routingMethod, int $coolingThreshold,
        int $cooldownMinutes, int $defaultWeight, int $defaultMaxDaily,
    ) {
        $this->name = $name;
        $this->routingMethod = $routingMethod;
        $this->coolingThreshold = $coolingThreshold;
        $this->cooldownMinutes = $cooldownMinutes;
        $this->defaultWeight = $defaultWeight;
        $this->defaultMaxDaily = $defaultMaxDaily;
    }

    /** 内置模板 */
    public static function presets(): array
    {
        return [
            self::balanced(),
            self::weightPriority(),
            self::safeMode(),
            self::highVolume(),
        ];
    }

    /** 均衡轮询: 权重随机，3 次失败冷却 30 分钟 */
    public static function balanced(): self
    {
        return new self('balanced', 'weighted', 3, 30, 3, 100);
    }

    /** 权重优先: 高权重 B 站优先，5 次失败才冷却 */
    public static function weightPriority(): self
    {
        return new self('weight_priority', 'weighted', 5, 60, 5, 200);
    }

    /** 安全模式: 轮询分配，1 次失败立即冷却，快速恢复 */
    public static function safeMode(): self
    {
        return new self('safe_mode', 'round_robin', 1, 15, 1, 30);
    }

    /** 大流量: 随机分配，10 次失败才冷却 */
    public static function highVolume(): self
    {
        return new self('high_volume', 'random', 10, 120, 5, 500);
    }

    /** 按名称查找预设 */
    public static function find(string $name): ?self
    {
        foreach (self::presets() as $p) {
            if ($p->name === $name) return $p;
        }
        return null;
    }

    public function toArray(): array
    {
        return [
            'name'              => $this->name,
            'routing_method'    => $this->routingMethod,
            'cooling_threshold' => $this->coolingThreshold,
            'cooldown_minutes'  => $this->cooldownMinutes,
            'default_weight'    => $this->defaultWeight,
            'default_max_daily' => $this->defaultMaxDaily,
        ];
    }
}
