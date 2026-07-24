<?php
/**
 * Lang — 轻量国际化（零依赖）
 *
 * 自动检测 Accept-Language 或 ?lang= 参数，加载对应语言包。
 * 用法: Lang::get('error.invalid_key')
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\I18n;

final class Lang
{
    private static string $locale = 'en';
    private static array $messages = [];
    private static bool $loaded = false;

    /** 初始化：自动检测语言 */
    public static function init(?string $forcedLocale = null): void
    {
        if ($forcedLocale) {
            self::$locale = in_array($forcedLocale, ['zh', 'en']) ? $forcedLocale : 'en';
        } elseif (isset($_GET['lang'])) {
            self::$locale = $_GET['lang'] === 'zh' ? 'zh' : 'en';
        } else {
            self::$locale = self::detectBrowser();
        }
        self::load();
    }

    /** 获取翻译 */
    public static function get(string $key, array $replace = []): string
    {
        if (!self::$loaded) self::load();
        $msg = self::$messages[$key] ?? $key;
        foreach ($replace as $k => $v) {
            $msg = str_replace("{{$k}}", (string)$v, $msg);
        }
        return $msg;
    }

    /** 当前语言代码 */
    public static function locale(): string { return self::$locale; }

    /** 所有消息（供前端 JS 使用） */
    public static function all(): array { return self::$messages; }

    private static function load(): void
    {
        $file = __DIR__ . '/' . self::$locale . '.php';
        if (file_exists($file)) {
            self::$messages = require $file;
        }
        self::$loaded = true;
    }

    private static function detectBrowser(): string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if (str_contains($header, 'zh')) return 'zh';
        return 'en';
    }
}
