<?php

declare(strict_types=1);

namespace Converge\Security;

/**
 * BotDetectorConfig — Bot 检测模式解析器
 *
 * 三模式:
 *   'off'    — 完全禁用，零开销
 *   'shadow' — 仅记录分析数据，不拦截（默认）
 *   'block'  — score > 60 拦截返回 204
 *
 * 优先级: kill_switch 文件 > 界面配置(JSON) > BOT_DETECT_MODE 环境变量 > 传入配置 > 默认 shadow
 */
class BotDetectorConfig
{
    public const MODE_OFF    = 'off';
    public const MODE_SHADOW = 'shadow';
    public const MODE_BLOCK  = 'block';

    private const VALID_MODES = [self::MODE_OFF, self::MODE_SHADOW, self::MODE_BLOCK];

    /**
     * 解析有效的 Bot 检测模式。
     */
    public static function resolve(?string $configValue = null, ?string $storagePath = null): string
    {
        // 1. 紧急 kill switch 文件
        if (self::isKillSwitchActive($storagePath)) {
            return self::MODE_OFF;
        }

        // 2. UI 界面配置 (JSON file, set by /api/bot-mode.php)
        $storageDir = $storagePath ?? (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 2) . '/storage');
        $modeFile = $storageDir . '/bot-mode.json';
        if (file_exists($modeFile)) {
            $data = json_decode(file_get_contents($modeFile), true);
            if (isset($data['mode']) && in_array($data['mode'], self::VALID_MODES, true)) {
                return $data['mode'];
            }
        }

        // 3. 环境变量覆盖
        $env = getenv('BOT_DETECT_MODE');
        if ($env !== false && in_array($env, self::VALID_MODES, true)) {
            return $env;
        }

        // 4. 传入的配置值
        if ($configValue !== null && in_array($configValue, self::VALID_MODES, true)) {
            return $configValue;
        }

        // 5. 默认 shadow
        return self::MODE_SHADOW;
    }

    /**
     * 检查 kill switch 文件是否存在。
     */
    public static function isKillSwitchActive(?string $storagePath = null): bool
    {
        $path = ($storagePath ?? dirname(__DIR__, 2) . '/modules/Tracking/storage')
            . '/bot_shadow.disabled';
        return is_file($path);
    }

    public static function isActive(?string $configValue = null, ?string $storagePath = null): bool
    {
        return self::resolve($configValue, $storagePath) !== self::MODE_OFF;
    }

    public static function isBlocking(?string $configValue = null, ?string $storagePath = null): bool
    {
        return self::resolve($configValue, $storagePath) === self::MODE_BLOCK;
    }
}
