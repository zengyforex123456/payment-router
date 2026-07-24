<?php
declare(strict_types=1);

namespace Converge\I18n;

/**
 * Locale — 统一语言管理（唯一权威来源）
 *
 * 原则:
 *   - 语言检测一次，存储一处
 *   - 翻译文件是唯一数据来源
 *   - 侧边栏+内容+JS 共用同一套翻译
 *
 * 用法:
 *   Locale::init();          // 入口文件顶部调用一次
 *   __('sidebar.tracking');  // 任何地方用 __() 取翻译
 */
class Locale
{
    private static ?string $lang = null;
    private static array $translations = [];
    private static bool $initialized = false;

    /**
     * 初始化：检测语言 + 加载翻译 + 三向绑定
     * 必须在任何输出之前调用
     */
    public static function init(): void
    {
        if (self::$initialized) return;

        // 1. 检测语言: URL > Session > Cookie > Accept-Language
        self::$lang = self::detect();

        // 2. 全局可用
        $GLOBALS['lang'] = self::$lang;

        // 3. 加载翻译文件
        $file = APP_ROOT . '/resources/lang/' . self::$lang . '.php';
        if (file_exists($file)) {
            self::$translations = require $file;
        } else {
            // Fallback: load English
            $enFile = APP_ROOT . '/resources/lang/en.php';
            self::$translations = file_exists($enFile) ? require $enFile : [];
        }

        self::$initialized = true;
    }

    /**
     * 取翻译。未找到时返回 key 本身或 default
     */
    public static function translate(string $key, ?string $default = null): string
    {
        if (!self::$initialized) {
            self::init();
        }
        return self::$translations[$key] ?? $default ?? $key;
    }

    /** 当前语言代码 */
    public static function lang(): string
    {
        if (!self::$initialized) self::init();
        return self::$lang;
    }

    /** 全部翻译数组（用于注入 JS） */
    public static function all(): array
    {
        if (!self::$initialized) self::init();
        return self::$translations;
    }

    /**
     * 注入到 JS: 返回 <script>window.I18N = {...}</script>
     * @param string[] $keys 需要暴露给前端的 key（空=全部）
     */
    public static function injectJS(array $keys = []): string
    {
        $data = [
            'lang' => self::lang(),
            't' => [],
        ];
        if (empty($keys)) {
            $data['t'] = self::all();
        } else {
            foreach ($keys as $k) {
                $data['t'][$k] = self::translate($k);
            }
        }
        return '<script>window.I18N=' . json_encode($data, JSON_UNESCAPED_UNICODE) . ';</script>';
    }

    // ═══════════════════════════════════════
    // Internal
    // ═══════════════════════════════════════

    private static function detect(): string
    {
        $lang = $_GET['lang']
            ?? $_SESSION['converge_lang']
            ?? $_COOKIE['converge_lang']
            ?? (str_contains($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 'zh') ? 'zh' : 'en');

        $allowed = ['zh', 'en'];
        if (!in_array($lang, $allowed, true)) {
            $lang = 'en';
        }

        // 三向绑定: URL → Session + Cookie
        if (!empty($_GET['lang'])) {
            $_SESSION['converge_lang'] = $lang;
            if (!headers_sent()) {
                setcookie('converge_lang', $lang, [
                    'expires' => time() + 86400 * 365,
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }
        // Session → Cookie 同步
        if (empty($_GET['lang']) && !empty($_SESSION['converge_lang'])) {
            if (($_COOKIE['converge_lang'] ?? '') !== $_SESSION['converge_lang']) {
                if (!headers_sent()) {
                    setcookie('converge_lang', $_SESSION['converge_lang'], [
                        'expires' => time() + 86400 * 365,
                        'path' => '/',
                        'secure' => true,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }
            }
        }

        return $lang;
    }
}
