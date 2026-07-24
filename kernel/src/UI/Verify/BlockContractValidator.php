<?php
declare(strict_types=1);
namespace Converge\UI\Verify;

/**
 * BlockContractValidator — 运行时 Props 合同验证
 *
 * 在 dev 模式下验证传入 Block::render() 的 props 是否符合 schema.json 声明。
 * 行业对标: React PropTypes (已废弃) → Zod/TypeScript 编译期检查。
 * PHP 无编译期，此为运行时可用的最优替代。
 *
 * 用法:
 *   BlockContractValidator::validate('hero', $props);
 */
class BlockContractValidator
{
    /** @var array<string, array> schema 缓存 */
    private static array $schemas = [];

    /** 是否 dev 模式（prod 静默跳过） */
    private static bool $isDev = true;

    public static function setDevMode(bool $dev): void
    {
        self::$isDev = $dev;
    }

    /**
     * 验证区块 props 是否符合 schema 声明
     *
     * @param string $type  区块类型 (hero, card, table...)
     * @param array  $props 实际传入的 props
     */
    public static function validate(string $type, array $props): void
    {
        if (!self::$isDev) return;
        if (empty($props)) return;

        $schema = self::loadSchema($type);
        if (!$schema || empty($schema['props'])) return;

        $declared = $schema['props'];
        $warnings = [];

        // 1. 检查多余属性（传了 schema 没声明的）
        foreach ($props as $key => $val) {
            if ($key === 'children' || $key === 'requiredRole' || $key === 'requiredPermission') continue;
            if (!isset($declared[$key])) {
                $warnings[] = "⚠️  Block '$type': unknown prop '$key' (not in schema)";
            }
        }

        // 2. 检查类型不匹配
        foreach ($props as $key => $val) {
            if (!isset($declared[$key])) continue;
            $expected = $declared[$key]['type'] ?? 'string';
            $actual = gettype($val);

            if ($expected === 'string' && !is_string($val)) {
                $warnings[] = "⚠️  Block '$type.$key': expected string, got $actual";
            }
            if ($expected === 'boolean' && !is_bool($val)) {
                $warnings[] = "⚠️  Block '$type.$key': expected boolean, got $actual";
            }
            if ($expected === 'array' && !is_array($val)) {
                $warnings[] = "⚠️  Block '$type.$key': expected array, got $actual";
            }
            if ($expected === 'number' && !is_int($val) && !is_float($val)) {
                $warnings[] = "⚠️  Block '$type.$key': expected number, got $actual";
            }
        }

        // 3. 输出警告（dev 环境 error_log）
        foreach ($warnings as $w) {
            error_log("[Converge Contract] $w");
        }
    }

    /** 加载并缓存 schema */
    private static function loadSchema(string $type): ?array
    {
        if (isset(self::$schemas[$type])) return self::$schemas[$type];

        $name = match($type) {
            'cta' => 'FinalCta', 'proof' => 'SocialProof', 'trust' => 'TrustBar',
            'how' => 'HowItWorks', 'features' => 'FeatureGrid', 'comparison' => 'Comparison',
            default => ucfirst($type),
        };

        $file = dirname(__DIR__, 2) . '/UI/Block/schema/' . $name . '.schema.json';
        if (!file_exists($file)) return null;

        $schema = json_decode(file_get_contents($file), true);
        self::$schemas[$type] = $schema;
        return $schema;
    }
}
