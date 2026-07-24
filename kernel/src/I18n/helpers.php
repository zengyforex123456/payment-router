<?php
declare(strict_types=1);

/**
 * I18n 全局辅助函数 — 通过 Composer autoload files 自动加载
 *
 * 所有 require vendor/autoload.php 的页面自动获得:
 *   __('sidebar.tracking')     → 翻译后的字符串
 *   lang()                     → 当前语言代码 'zh' | 'en'
 */

if (!function_exists('__')) {
    /**
     * 取翻译文本
     *
     * @param string $key     翻译键 (如 'sidebar.tracking')
     * @param string|null $default 未找到时使用的默认值
     * @return string 翻译后的文本
     */
    function __(string $key, ?string $default = null): string
    {
        return \Converge\I18n\Locale::translate($key, $default);
    }
}

if (!function_exists('lang')) {
    /**
     * 当前语言代码
     *
     * @return string 'zh' | 'en'
     */
    function lang(): string
    {
        return \Converge\I18n\Locale::lang();
    }
}
