<?php
declare(strict_types=1);

namespace Converge\Security;

/**
 * Totp — RFC 6238 TOTP 实现 (零外部依赖)
 *
 * 用法:
 *   $secret = Totp::generateSecret();       // 生成密钥
 *   $valid  = Totp::verify($code, $secret); // 验证 6 位码
 *   $uri    = Totp::qrCodeUri($secret, 'admin@converge.io'); // QR 码 URI
 */
class Totp
{
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const ALGORITHM = 'sha1';

    /** 生成 Base32 密钥 (16 字节 → 26 字符) */
    public static function generateSecret(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    /** 验证一次性码 */
    public static function verify(string $code, string $secret, int $window = 1): bool
    {
        $code = trim($code);
        if (strlen($code) !== self::DIGITS || !ctype_digit($code)) return false;

        $timeSlice = (int)floor(time() / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (self::compute($secret, $timeSlice + $i) === $code) return true;
        }
        return false;
    }

    /** 计算当前窗口的 TOTP 码 */
    public static function compute(string $secret, ?int $timeSlice = null): string
    {
        $timeSlice ??= (int)floor(time() / self::PERIOD);
        $time = pack('J', $timeSlice); // 64-bit big-endian
        $key = self::base32Decode($secret);
        $hash = hash_hmac(self::ALGORITHM, $time, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = (ord($hash[$offset]) & 0x7F) << 24
                | (ord($hash[$offset + 1]) & 0xFF) << 16
                | (ord($hash[$offset + 2]) & 0xFF) << 8
                | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string)($binary % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Google Authenticator 兼容的 QR 码 URI */
    public static function qrCodeUri(string $secret, string $email, string $issuer = 'Converge'): string
    {
        $label = rawurlencode($issuer . ':' . $email);
        return "otpauth://totp/{$label}?secret={$secret}&issuer=" . rawurlencode($issuer);
    }

    /** 生成恢复码 (8 个, 每码 12 字符) */
    public static function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = bin2hex(random_bytes(6));
        }
        return $codes;
    }

    private static function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(rtrim($secret, '='));
        $binary = '';
        foreach (str_split($secret) as $char) {
            $val = strpos($alphabet, $char);
            if ($val === false) continue;
            $binary .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) < 8) break;
            $result .= chr(bindec($byte));
        }
        return $result;
    }
}
