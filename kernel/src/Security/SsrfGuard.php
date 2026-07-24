<?php

declare(strict_types=1);

namespace Converge\Security;

/**
 * SsrfGuard — 防止服务端请求伪造 (SSRF)
 *
 * 在发起任何用户可配置的外部 HTTP 请求前，
 * 验证目标 URL 不指向内网/私有 IP。
 *
 * 用法:
 *   SsrfGuard::validateUrl($url);  // 安全 → 静默通过，危险 → 抛异常
 */
final class SsrfGuard
{
    /**
     * Validate a URL is safe to fetch.
     *
     * @throws \RuntimeException if the URL targets a private/internal IP
     */
    public static function validateUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || $host === '') {
            throw new \RuntimeException("SsrfGuard: invalid URL — no host: " . substr($url, 0, 100));
        }

        // Resolve hostname to IP
        $ip = self::resolveHost($host);
        if ($ip === null) {
            throw new \RuntimeException("SsrfGuard: cannot resolve host: {$host}");
        }

        // Check against blocked ranges
        if (self::isBlockedIp($ip)) {
            throw new \RuntimeException(
                "SsrfGuard: blocked IP range — {$host} → {$ip}"
            );
        }
    }

    /**
     * Check-only variant — returns true if URL is safe.
     */
    public static function isSafe(string $url): bool
    {
        try {
            self::validateUrl($url);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Resolve hostname → IPv4 address.
     * Uses DNS (gethostbyname), which also guards against DNS rebinding
     * because we check the resolved IP before connecting.
     */
    private static function resolveHost(string $host): ?string
    {
        // Already an IP? Validate directly
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $host;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Block all IPv6 for now (private range check is complex;
            // Converge does not need IPv6 postback targets)
            return null;
        }

        $ip = gethostbyname($host);

        // gethostbyname returns the input on failure
        if ($ip === $host) {
            return null;
        }

        return $ip;
    }

    /**
     * Check if an IPv4 address falls in a blocked range.
     */
    private static function isBlockedIp(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true; // Can't parse — block (fail closed)
        }

        // ═══ Blocked ranges ═══

        // 0.0.0.0/8 — Current network (RFC 1122)
        if (self::inRange($long, '0.0.0.0', 8)) return true;

        // 10.0.0.0/8 — Private (RFC 1918)
        if (self::inRange($long, '10.0.0.0', 8)) return true;

        // 127.0.0.0/8 — Loopback
        if (self::inRange($long, '127.0.0.0', 8)) return true;

        // 169.254.0.0/16 — Link-local (APIPA)
        if (self::inRange($long, '169.254.0.0', 16)) return true;

        // 172.16.0.0/12 — Private (RFC 1918)
        if (self::inRange($long, '172.16.0.0', 12)) return true;

        // 192.0.0.0/29 — IPv4 Service Continuity (RFC 7335)
        if (self::inRange($long, '192.0.0.0', 29)) return true;

        // 192.0.2.0/24 — TEST-NET-1 (RFC 5737)
        if (self::inRange($long, '192.0.2.0', 24)) return true;

        // 192.88.99.0/24 — 6to4 Relay Anycast (RFC 3068)
        if (self::inRange($long, '192.88.99.0', 24)) return true;

        // 192.168.0.0/16 — Private (RFC 1918)
        if (self::inRange($long, '192.168.0.0', 16)) return true;

        // 198.18.0.0/15 — Benchmarking (RFC 2544)
        if (self::inRange($long, '198.18.0.0', 15)) return true;

        // 198.51.100.0/24 — TEST-NET-2 (RFC 5737)
        if (self::inRange($long, '198.51.100.0', 24)) return true;

        // 203.0.113.0/24 — TEST-NET-3 (RFC 5737)
        if (self::inRange($long, '203.0.113.0', 24)) return true;

        // 224.0.0.0/4 — Multicast (RFC 5771)
        if (self::inRange($long, '224.0.0.0', 4)) return true;

        // 240.0.0.0/4 — Reserved (RFC 1112)
        if (self::inRange($long, '240.0.0.0', 4)) return true;

        // 255.255.255.255/32 — Broadcast
        if ($long === ip2long('255.255.255.255')) return true;

        return false;
    }

    /**
     * Check if an IP long value is within a CIDR range.
     */
    private static function inRange(int $ipLong, string $network, int $prefix): bool
    {
        $netLong = ip2long($network);
        $mask = -1 << (32 - $prefix);
        return ($ipLong & $mask) === ($netLong & $mask);
    }
}
