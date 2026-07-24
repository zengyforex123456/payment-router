<?php
declare(strict_types=1);
namespace Converge\UI\Page;

/**
 * BlockRegistry — 区块自注册，取代 PageRenderer 中的 classMap 硬编码
 *
 * 自动发现 src/UI/Block/ 目录下的所有区块类。
 * 新 Block 只需创建文件，无需修改 PageRenderer。
 *
 * 也可手动注册覆盖：
 *   BlockRegistry::register('hero', Hero::class);
 */
class BlockRegistry
{
    /** @var array<string, class-string> */
    private static array $blocks = [];

    private static bool $scanned = false;

    /** 手动注册（覆盖自动发现） */
    public static function register(string $type, string $class): void
    {
        self::$blocks[$type] = $class;
    }

    /** 解析区块类型 → 类名 */
    public static function resolve(string $type): ?string
    {
        self::ensureScanned();
        return self::$blocks[$type] ?? null;
    }

    /** 列出所有已注册区块 */
    public static function list(): array
    {
        self::ensureScanned();
        return self::$blocks;
    }

    /** 自动扫描 Block 目录 */
    private static function ensureScanned(): void
    {
        if (self::$scanned) return;
        self::$scanned = true;

        $dir = dirname(__DIR__, 1) . '/Block';
        if (!is_dir($dir)) return;

        foreach (glob($dir . '/*.php') as $file) {
            $name = basename($file, '.php');
            // 排除非区块类（schema 目录、辅助文件）
            if ($name === 'schema') continue;

            $class = "Converge\\UI\\Blocks\\{$name}";
            if (class_exists($class) && method_exists($class, 'render')) {
                // 类名 → type: "Card" → "card", "FeatureGrid" → "featuregrid"
                $type = strtolower($name);
                self::$blocks[$type] = $class;
            }
        }

        // 手动注册特殊映射（type ≠ 类名）
        $overrides = [
            'formfield'  => 'Converge\\UI\\Blocks\\FormField',
            'cta'        => 'Converge\\UI\\Blocks\\FinalCta',
            'proof'      => 'Converge\\UI\\Blocks\\SocialProof',
            'trust'      => 'Converge\\UI\\Blocks\\TrustBar',
            'how'        => 'Converge\\UI\\Blocks\\HowItWorks',
            'features'   => 'Converge\\UI\\Blocks\\FeatureGrid',
            'comparison' => 'Converge\\UI\\Blocks\\Comparison',
        ];
        foreach ($overrides as $type => $class) {
            self::$blocks[$type] = $class;
        }

        // UI 根组件
        self::$blocks['button'] = 'Converge\\UI\\Button';
        self::$blocks['badge']  = 'Converge\\UI\\Badge';
    }

    /** 重置（测试用） */
    public static function reset(): void
    {
        self::$blocks = [];
        self::$scanned = false;
    }
}
