<?php

declare(strict_types=1);

namespace Converge\Security;

/**
 * SecurityHeaders — 统一安全响应头
 *
 * 用法: SecurityHeaders::send();
 * 在任意 PHP 页面顶部调用（在 header() 调用之后的任何输出之前）。
 */
final class SecurityHeaders
{
    /**
     * Send all security headers.
     * Call after session_start() but before any output.
     */
    public static function send(bool $isHttps = false): void
    {
        // Skip if disabled via .env (testing)
        if (defined('SECURITY_HEADERS_ENABLED') && !SECURITY_HEADERS_ENABLED) {
            return;
        }

        // Prevent MIME-type sniffing (IE/Chrome)
        header('X-Content-Type-Options: nosniff');

        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Limit referrer leakage to cross-origin requests
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Disable unused browser features
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // HSTS — only send over HTTPS
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // CSP — relaxed to match Converge's inline-style architecture.
        // Tighten script-src after migrating inline event handlers.
        header("Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' https://js.stripe.com; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; "
            . "frame-src 'self' https://js.stripe.com https://hooks.stripe.com; "
            . "connect-src 'self' https://api.stripe.com; "
            . "frame-ancestors 'self'");
    }
}
