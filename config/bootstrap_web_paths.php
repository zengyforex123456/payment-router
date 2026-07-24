<?php

declare(strict_types=1);

/**
 * bootstrap_web_paths.php — URL prefix constants
 *
 * Ensures ASSETS_BASE_URL / APP_BASE_URL / PUBLIC_WEB_PREFIX are defined.
 * Required by public/api/ entry points.
 */
if (!defined('BASE_URL')) {
    return;
}

if (!defined('PUBLIC_WEB_PREFIX')) {
    if (defined('ASSETS_BASE_URL') && ASSETS_BASE_URL !== '') {
        $base = rtrim(BASE_URL, '/');
        $prefix = str_starts_with(ASSETS_BASE_URL, $base)
            ? substr(ASSETS_BASE_URL, strlen($base))
            : '';
        define('PUBLIC_WEB_PREFIX', $prefix);
    } else {
        define('PUBLIC_WEB_PREFIX', '');
    }
}

if (!defined('ASSETS_BASE_URL') || ASSETS_BASE_URL === '') {
    define('ASSETS_BASE_URL', rtrim(BASE_URL, '/') . PUBLIC_WEB_PREFIX);
}

if (!defined('APP_BASE_URL') || APP_BASE_URL === '') {
    define('APP_BASE_URL', rtrim(BASE_URL, '/') . PUBLIC_WEB_PREFIX);
}

if (!defined('SINGLE_ADMIN_MODE')) {
    define('SINGLE_ADMIN_MODE', true);
}
