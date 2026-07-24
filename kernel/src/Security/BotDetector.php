<?php

declare(strict_types=1);

namespace Converge\Security;

use Converge\Foundation\Observability\StructuredLogger;
use Converge\Traceability\Infrastructure\EventStore;

/**
 * BotDetector — 🛡️ 点击欺诈检测 (对标 Binom Protect)
 *
 * 五层检测引擎:
 *   L1: IP 黑名单 (已知数据中心/代理/VPN IP)
 *   L2: 点击频率检测 (同 IP 短时间内大量点击)
 *   L3: User-Agent 指纹 (已知爬虫/机器人 UA)
 *   L4: 行为模式 (无鼠标移动、无 JS 执行、瞬间跳转)
 *   L5: 机器学习特征 (referrer 缺失、随机 UA、异常 geo)
 *
 * 检测结果:
 *   score 0-100 → < 30: 正常, 30-60: 可疑, > 60: 机器人
 *
 * 策略 (按分数):
 *   < 30: 正常追踪
 *   30-60: 标记为可疑，但仍计入统计 (可过滤查看)
 *   > 60: 重定向到空白页，不计入统计
 *   > 90: IP 自动加入临时黑名单 (24h)
 */
class BotDetector
{
    private \mysqli $db;
    private ?EventStore $eventStore;
    private ?StructuredLogger $logger;

    /** IP 黑名单缓存 (避免重复查 DB) */
    private array $blacklistCache = [];

    /** 临时黑名单 (本进程内) */
    private array $tempBlacklist = [];

    /** @var array<string, array> 点击频率窗口 */
    private array $clickWindow = [];

    private int $frequencyWindowSec = 60;   // 60秒窗口
    private int $maxClicksPerWindow = 20;   // 同 IP 60秒内最多20次点击
    private int $tempBanSeconds = 86400;    // 临时封禁 24h
    private bool $reverseDns = true;        // L1 数据中心检测用反向DNS; 热路径应关(gethostbyaddr阻塞)
    private ?BotRateLimiter $rateLimiter = null; // L2 共享频率计数器 (null=进程内)

    /** Sub-second burst detection: same IP within N milliseconds */
    private int $burstWindowMs = 500;       // 500ms = 0.5 seconds
    private int $burstMaxClicks = 2;        // 2 clicks in 500ms = bot

    /** Memory circuit breaker: max EventStore writes per IP per second (protect SQLite on hot path) */
    private array $esRateLimit = [];        // [ip => last_write_ts]
    private int $esRateLimitWindowSec = 1;  // 1 EventStore write per IP per second max

    public function __construct(
        \mysqli $db,
        ?EventStore $eventStore = null,
        ?StructuredLogger $logger = null,
        bool $reverseDns = true,
        ?BotRateLimiter $rateLimiter = null,
    ) {
        $this->db = $db;
        $this->eventStore = $eventStore;
        $this->logger = $logger;
        $this->reverseDns = $reverseDns;
        $this->rateLimiter = $rateLimiter;
    }

    // ═══════════════════════════════════════
    // 主检测入口
    // ═══════════════════════════════════════

    /**
     * Analyze a click and return bot probability score.
     *
     * @param array $click  {ip, ua, referrer, campaign_id, ...}
     * @param array $extra  {js_enabled, mouse_moved, time_on_page_ms, ...}
     * @return BotVerdict
     */
    public function analyze(array $click, array $extra = []): BotVerdict
    {
        $ip = $click['ip'] ?? '';
        $ua = $click['ua'] ?? '';
        $referrer = $click['referrer'] ?? '';

        $score = 0;
        $reasons = [];

        // L1: IP 黑名单检查
        $l1 = $this->checkIpBlacklist($ip);
        $score += $l1['score'];
        if ($l1['score'] > 0) $reasons[] = $l1['reason'];

        // L2: 点击频率检测
        $l2 = $this->checkClickFrequency($ip);
        $score += $l2['score'];
        if ($l2['score'] > 0) $reasons[] = $l2['reason'];

        // L2.5: Sub-second burst detection (bot clicks within 500ms)
        $l25 = $this->checkBurstInterval($ip);
        $score += $l25['score'];
        if ($l25['score'] > 0) $reasons[] = $l25['reason'];

        // L3: UA 检测
        $l3 = $this->checkUserAgent($ua);
        $score += $l3['score'];
        if ($l3['score'] > 0) $reasons[] = $l3['reason'];

        // L4: 行为模式检测
        $l4 = $this->checkBehavior($click, $extra);
        $score += $l4['score'];
        if ($l4['score'] > 0) $reasons[] = $l4['reason'];

        // L5: 组合特征检测
        $l5 = $this->checkCompositeSignals($click, $extra);
        $score += $l5['score'];
        if ($l5['score'] > 0) $reasons[] = $l5['reason'];

        // Cap score at 100
        $score = min(100, $score);

        $verdict = new BotVerdict(
            score: $score,
            isBot: $score > 60,
            isSuspicious: $score >= 30 && $score <= 60,
            reasons: $reasons,
            action: $this->determineAction($score),
        );

        // Auto-ban if score > 90
        if ($score > 90) {
            $this->tempBanIp($ip, "Auto-ban: bot score {$score} — " . implode('; ', $reasons));
        }

        // Log high scores
        if ($score >= 30) {
            $this->log('warning', "Bot score {$score} for IP {$ip}", [
                'reasons' => $reasons,
                'ua' => substr($ua, 0, 100),
            ]);

            // EventStore append — fail-fast (B) with memory circuit breaker + file escape hatch
            if ($this->eventStore && $this->shouldWriteEventStore("ip:{$ip}")) {
                try {
                    $this->eventStore->append(
                        aggregateId: "ip:{$ip}",
                        eventType: 'bot_detection',
                        payload: [
                            'score' => $score,
                            'reasons' => $reasons,
                            'action' => $verdict->action,
                        ],
                    );
                } catch (\Throwable $e) {
                    // Dead Letters queue (5→10→20→40→80 min exponential backoff)
                    $this->writeDeadLetterSafe('bot_detection', "ip:{$ip}", [
                        'score' => $score, 'reasons' => $reasons, 'action' => $verdict->action,
                    ], $e->getMessage());
                }
            }
        }

        return $verdict;
    }

    // ═══════════════════════════════════════
    // L1: IP 黑名单
    // ═══════════════════════════════════════

    private function checkIpBlacklist(string $ip): array
    {
        if (empty($ip)) {
            return ['score' => 10, 'reason' => 'L1: Missing IP'];
        }

        // Check temp blacklist (in-memory)
        if (isset($this->tempBlacklist[$ip])) {
            $ban = $this->tempBlacklist[$ip];
            if (time() < $ban['expires']) {
                return ['score' => 100, 'reason' => "L1: Temp-banned until " . date('H:i', $ban['expires'])];
            }
            unset($this->tempBlacklist[$ip]);
        }

        // Check DB blacklist (cached)
        if (isset($this->blacklistCache[$ip])) {
            return $this->blacklistCache[$ip]
                ? ['score' => 100, 'reason' => 'L1: IP blacklisted']
                : ['score' => 0, 'reason' => ''];
        }

        // Check known bot/data-center IP ranges (simplified)
        if ($this->isDataCenterIp($ip)) {
            $this->blacklistCache[$ip] = true;
            return ['score' => 40, 'reason' => 'L1: Data-center IP range'];
        }

        // Check DB
        $stmt = $this->db->prepare(
            "SELECT reason FROM ip_blacklist WHERE ip = ? AND (expires_at IS NULL OR expires_at > NOW())"
        );
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            $this->blacklistCache[$ip] = true;
            return ['score' => 100, 'reason' => 'L1: IP blacklisted — ' . ($row['reason'] ?? 'manual')];
        }

        $this->blacklistCache[$ip] = false;
        return ['score' => 0, 'reason' => ''];
    }

    // ═══════════════════════════════════════
    // L2: 点击频率
    // ═══════════════════════════════════════

    private function checkClickFrequency(string $ip): array
    {
        if (empty($ip)) return ['score' => 0, 'reason' => ''];

        // 优先使用 Redis 共享计数器 (多容器安全)
        if ($this->rateLimiter !== null && $this->rateLimiter->isAvailable()) {
            return $this->rateLimiter->getScore($ip, $this->maxClicksPerWindow, $this->frequencyWindowSec);
        }

        // 降级: 进程内计数
        $now = microtime(true);
        $windowStart = $now - $this->frequencyWindowSec;

        // Clean expired entries
        foreach ($this->clickWindow as $ipKey => $timestamps) {
            $this->clickWindow[$ipKey] = array_filter(
                $timestamps,
                fn($t) => $t > $windowStart
            );
        }

        // Record this click
        $this->clickWindow[$ip][] = $now;

        $count = count($this->clickWindow[$ip]);

        if ($count > $this->maxClicksPerWindow * 3) {
            return ['score' => 80, 'reason' => "L2: {$count} clicks in {$this->frequencyWindowSec}s (3x limit)"];
        }
        if ($count > $this->maxClicksPerWindow * 2) {
            return ['score' => 50, 'reason' => "L2: {$count} clicks in {$this->frequencyWindowSec}s (2x limit)"];
        }
        if ($count > $this->maxClicksPerWindow) {
            return ['score' => 25, 'reason' => "L2: {$count} clicks in {$this->frequencyWindowSec}s"];
        }

        return ['score' => 0, 'reason' => ''];
    }

    // ═══ L2.5: Sub-second burst detection (bot clicks within 500ms) ═══
    private function checkBurstInterval(string $ip): array
    {
        if (empty($ip)) return ['score' => 0, 'reason' => ''];
        $now = (int)(microtime(true) * 1000);
        if (!isset($this->clickWindow['_b_' . $ip])) $this->clickWindow['_b_' . $ip] = [];
        $bursts = &$this->clickWindow['_b_' . $ip];
        $bursts[] = $now;
        if (count($bursts) > 5) array_shift($bursts);
        if (count($bursts) >= 2) {
            $delta = $bursts[count($bursts)-1] - $bursts[count($bursts)-2];
            if ($delta < $this->burstWindowMs) {
                return ['score' => 95, 'reason' => 'L2.5: 2 clicks in ' . round($delta) . 'ms — definitive bot'];
            }
        }
        return ['score' => 0, 'reason' => ''];
    }

    // ═══════════════════════════════════════
    // L3: User-Agent 检测
    // ═══════════════════════════════════════

    private function checkUserAgent(string $ua): array
    {
        if (empty($ua)) {
            return ['score' => 30, 'reason' => 'L3: Empty User-Agent'];
        }

        $uaLower = strtolower($ua);

        // Known bot signatures
        $botPatterns = [
            'googlebot' => 10,      // Googlebot (could be spoofed, low score)
            'bingbot' => 10,
            'baiduspider' => 10,
            'ahrefsbot' => 30,
            'semrushbot' => 30,
            'dotbot' => 30,
            'mj12bot' => 30,
            'scrapy' => 60,
            'python-requests' => 60,
            'python-urllib' => 60,
            'curl/' => 50,
            'wget/' => 50,
            'go-http-client' => 50,
            'java/' => 40,
            'nutch' => 40,
            'scan' => 40,
            'crawler' => 25,
            'spider' => 25,
            'bot' => 20,
            'headless' => 70,
            'phantom' => 70,
            'selenium' => 60,
            'puppeteer' => 40,
        ];

        foreach ($botPatterns as $pattern => $score) {
            if (str_contains($uaLower, $pattern)) {
                return ['score' => $score, 'reason' => "L3: UA matches '{$pattern}'"];
            }
        }

        // Very short UA
        if (strlen($ua) < 20) {
            return ['score' => 15, 'reason' => 'L3: Suspiciously short User-Agent'];
        }

        return ['score' => 0, 'reason' => ''];
    }

    // ═══════════════════════════════════════
    // L4: 行为模式
    // ═══════════════════════════════════════

    private function checkBehavior(array $click, array $extra): array
    {
        $score = 0;
        $signals = [];

        // Redirectless tracking: client-side signals
        $jsEnabled = $extra['js_enabled'] ?? null;
        $mouseMoved = $extra['mouse_moved'] ?? null;
        $timeOnPage = $extra['time_on_page_ms'] ?? null;

        if ($jsEnabled === false) {
            $score += 40;
            $signals[] = 'no JS';
        }

        if ($mouseMoved === false) {
            $score += 20;
            $signals[] = 'no mouse';
        }

        // Instant redirect (less than 100ms on page)
        if ($timeOnPage !== null && $timeOnPage < 100) {
            $score += 15;
            $signals[] = "instant ({$timeOnPage}ms)";
        }

        // Referrer missing + empty UA = strong bot signal
        if (empty($click['referrer']) && empty($click['ua'])) {
            $score += 35;
            $signals[] = 'no referrer + no UA';
        }

        if (!empty($signals)) {
            return ['score' => $score, 'reason' => 'L4: ' . implode(', ', $signals)];
        }

        return ['score' => 0, 'reason' => ''];
    }

    // ═══════════════════════════════════════
    // L5: 组合特征
    // ═══════════════════════════════════════

    private function checkCompositeSignals(array $click, array $extra): array
    {
        $score = 0;
        $signals = [];

        // Geo-IP mismatch: IP country ≠ browser language country
        $country = $click['country'] ?? '';
        $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

        if ($country === 'CN' && !str_contains($acceptLang, 'zh')) {
            $score += 10;
            $signals[] = 'CN IP + non-CN lang';
        }

        // No Accept-Language header at all
        if (empty($acceptLang)) {
            $score += 15;
            $signals[] = 'no Accept-Language';
        }

        // No Accept header
        if (empty($_SERVER['HTTP_ACCEPT'] ?? '')) {
            $score += 10;
            $signals[] = 'no Accept header';
        }

        // Random-looking click_id pattern (very long random string)
        if (isset($click['click_id']) && strlen($click['click_id']) > 50) {
            $score += 5;
            $signals[] = 'suspicious click_id length';
        }

        if (!empty($signals)) {
            return ['score' => $score, 'reason' => 'L5: ' . implode(', ', $signals)];
        }

        return ['score' => 0, 'reason' => ''];
    }

    // ═══════════════════════════════════════
    // IP 管理
    // ═══════════════════════════════════════

    private function tempBanIp(string $ip, string $reason): void
    {
        $this->tempBlacklist[$ip] = [
            'expires' => time() + $this->tempBanSeconds,
            'reason' => $reason,
        ];

        // Persist to DB (best-effort, non-blocking)
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO ip_blacklist (ip, reason, expires_at, created_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason), expires_at = VALUES(expires_at)"
            );
            $seconds = $this->tempBanSeconds;
            $stmt->bind_param('ssi', $ip, $reason, $seconds);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            $this->log('error', "Failed to persist temp ban: " . $e->getMessage());
        }

        $this->log('alert', "IP temp-banned: {$ip} — {$reason}");

        if ($this->eventStore && $this->shouldWriteEventStore("ip:{$ip}")) {
            try {
                $this->eventStore->append("ip:{$ip}", 'ip_banned', [
                    'reason' => $reason,
                    'duration_seconds' => $this->tempBanSeconds,
                ]);
            } catch (\Throwable $e) {
                $this->writeDeadLetterSafe('ip_banned', "ip:{$ip}", [
                    'reason' => $reason, 'duration_seconds' => $this->tempBanSeconds,
                ], $e->getMessage());
            }
        }
    }

    /**
     * Check if IP belongs to known data center ranges (AWS, GCP, Azure, DO).
     * Simplified version — production should use a proper IP database.
     */
    private function isDataCenterIp(string $ip): bool
    {
        // 热路径: 反向DNS(gethostbyaddr)可能数十~数百ms → 影子/热路径默认禁用
        if (!$this->reverseDns) {
            return false;
        }
        // Check common ASN patterns via reverse DNS (simplified)
        $hostname = @gethostbyaddr($ip);
        if ($hostname === false || $hostname === $ip) {
            return false;
        }

        $dcPatterns = [
            'amazonaws.com',
            'googleusercontent.com',
            'googlecloud.com',
            'azure.com',
            'digitalocean.com',
            'linode.com',
            'vultr.com',
            'ovh.net',
            'hetzner.com',
            'server.',
            'hosting.',
            'datacenter.',
            'colo.',
            'cloud.',
        ];

        $hostnameLower = strtolower($hostname);
        foreach ($dcPatterns as $pattern) {
            if (str_contains($hostnameLower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Manually blacklist an IP.
     */
    public function blacklist(string $ip, string $reason): void
    {
        $this->blacklistCache[$ip] = true;
        $stmt = $this->db->prepare(
            "INSERT INTO ip_blacklist (ip, reason, created_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE reason = VALUES(reason)"
        );
        $stmt->bind_param('ss', $ip, $reason);
        $stmt->execute();
        $stmt->close();

        $this->log('alert', "IP manually blacklisted: {$ip} — {$reason}");
    }

    /**
     * Remove IP from blacklist.
     */
    public function unblacklist(string $ip): void
    {
        unset($this->blacklistCache[$ip]);
        $stmt = $this->db->prepare("DELETE FROM ip_blacklist WHERE ip = ?");
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $stmt->close();
    }

    /** @return list<array> */
    public function getBlacklist(): array
    {
        $result = $this->db->query(
            "SELECT ip, reason, created_at, expires_at FROM ip_blacklist
             WHERE expires_at IS NULL OR expires_at > NOW()
             ORDER BY created_at DESC LIMIT 100"
        );
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Clean expired bans.
     */
    public function cleanExpired(): int
    {
        $stmt = $this->db->prepare("DELETE FROM ip_blacklist WHERE expires_at IS NOT NULL AND expires_at <= NOW()");
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        return $deleted;
    }

    public function getStats(): array
    {
        $result = $this->db->query("SELECT COUNT(*) as cnt FROM ip_blacklist WHERE expires_at IS NULL OR expires_at > NOW()");
        $row = $result->fetch_assoc();
        return [
            'blacklisted_ips' => (int)($row['cnt'] ?? 0),
            'temp_banned' => count($this->tempBlacklist),
            'click_window_ips' => count($this->clickWindow),
        ];
    }

    // ═══ Resilience helpers ═══

    /**
     * Memory circuit breaker: rate-limit EventStore writes per IP.
     * Prevents SQLite saturation under DDoS — at most 1 write per IP per second.
     * Hot path: O(1) array access, zero IO.
     */
    private function shouldWriteEventStore(string $key): bool
    {
        $now = microtime(true);
        $last = $this->esRateLimit[$key] ?? 0;
        if ($now - $last < $this->esRateLimitWindowSec) {
            // Rate-limited: skip EventStore write, in-memory state already active
            $this->log('debug', "EventStore write rate-limited for {$key}");
            return false;
        }
        $this->esRateLimit[$key] = $now;

        // Garbage-collect old entries (every 100 writes)
        if (count($this->esRateLimit) > 1000) {
            $cutoff = $now - 10;
            foreach ($this->esRateLimit as $k => $ts) {
                if ($ts < $cutoff) unset($this->esRateLimit[$k]);
            }
        }

        return true;
    }

    /**
     * Dead Letter safe write with file-based escape hatch.
     * DB Dead Letter → file log → memory (never silent loss).
     */
    private function writeDeadLetterSafe(string $eventType, string $aggregateId, array $payload, string $error): void
    {
        // Tier 1: Dead Letters table
        if ($this->eventStore) {
            try {
                $this->eventStore->writeDeadLetter(
                    eventType: $eventType,
                    aggregateId: $aggregateId,
                    payload: json_encode($payload, JSON_UNESCAPED_SLASHES), /* AlpineHelper omit: EventStore payload */
                    error: $error,
                );
                return; // success
            } catch (\Throwable) { /* fall through to escape hatch */ }
        }

        // Tier 2: File-based escape hatch (survives DB outage)
        try {
            $logDir = defined('STORAGE_PATH') ? STORAGE_PATH . '/logs' : sys_get_temp_dir();
            @mkdir($logDir, 0755, true);
            $line = date('c') . " | DEAD_LETTER_LOST | {$eventType} | {$aggregateId} | {$error} | "
                . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n"; /* AlpineHelper omit: file log, not HTML */
            @file_put_contents($logDir . '/dead_letters_fatal.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) { /* Tier 3: memory-only — log at least */ }

        // Tier 3: Structured logger (last resort)
        if ($this->logger) {
            $this->logger->log('critical', "DEAD_LETTER_LOST: {$eventType} for {$aggregateId}", [
                'error' => $error, 'payload' => $payload,
            ]);
        }
    }

    private function determineAction(int $score): string
    {
        if ($score > 90) return 'block_and_ban';
        if ($score > 60) return 'block';
        if ($score >= 30) return 'flag_suspicious';
        return 'allow';
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}
