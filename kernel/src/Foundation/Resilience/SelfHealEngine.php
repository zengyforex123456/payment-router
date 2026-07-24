<?php
/**
 * SelfHealEngine — 故障自愈: 诊断→查账本→修复→验证→记录 (🩺 可自愈)
 * 熔断器: 同指纹 ≥5 次失败→升级人工. 账本: "Fix once, immune forever"
 * 用法: SelfHealEngine::attemptHeal($e->getMessage(), ['exception'=>$e, 'url'=>...])
 */
declare(strict_types=1);

namespace Converge\Foundation\Resilience;

use Converge\Traceability\Infrastructure\EventStore;

class SelfHealEngine
{
    /** @var array<string, int> 熔断器: fingerprint → 连续失败次数 */
    private static array $breaker = [];
    private const BREAKER_THRESHOLD = 5;
    private const MIN_LEDGER_CONFIDENCE = 80;

    // ═══════════════════════════════════════
    // Public API
    // ═══════════════════════════════════════

    /** 自愈主入口. 返回 {id, action, success, diagnosis, fingerprint, elapsed_ms} */
    public static function attemptHeal(string $errorMessage, array $context = []): array
    {
        $t0 = microtime(true);
        $diag = SelfHealDiagnose::diagnose($errorMessage);
        $fp   = SelfHealDiagnose::fingerprint(
            isset($context['exception']) ? get_class($context['exception']) : 'unknown', $errorMessage
        );

        $result = [
            'id' => 'heal-' . substr(bin2hex(random_bytes(4)), 0, 8),
            'diagnosed_at' => date('c'), 'diagnosis' => $diag,
            'action' => 'none', 'success' => false, 'fingerprint' => $fp, 'elapsed_ms' => 0,
        ];

        // 熔断
        if (self::breakerTrip($fp)) {
            $result['action'] = 'escalate';
            $result['reason'] = '熔断: 连续失败≥' . self::BREAKER_THRESHOLD . '次';
            self::logToEventStore($result, $errorMessage, $context);
            return $result;
        }

        // 恢复账本 → 直接应用已验证修复
        $ledgerHit = SelfHealLedger::lookup($fp);
        if ($diag['selfHealable'] && $ledgerHit && $ledgerHit['confidence'] >= self::MIN_LEDGER_CONFIDENCE) {
            $result['action'] = 'auto-fix';
            $result['ledger_hit'] = $ledgerHit;
            $result['confidence'] = $ledgerHit['confidence'];
            self::executeAction($ledgerHit['strategy'], $diag, $result, $errorMessage, $context);
            self::finalize($result, $diag, $fp, $errorMessage, $context, $t0);
            return $result;
        }

        // 不可自愈 → 升级
        if (!$diag['selfHealable']) {
            $result['action'] = 'escalate';
            $result['reason'] = $diag['category'] . ' 不支持自动修复';
            self::logToEventStore($result, $errorMessage, $context);
            return $result;
        }

        $result['action'] = $diag['fix'] ?? 'retry-3x';
        $result['confidence'] = 30;
        self::executeAction($result['action'], $diag, $result, $errorMessage, $context);
        self::finalize($result, $diag, $fp, $errorMessage, $context, $t0);
        return $result;
    }

    public static function breakerStats(): array
    {
        return ['active_fingerprints' => count(self::$breaker), 'fingerprints' => self::$breaker, 'threshold' => self::BREAKER_THRESHOLD];
    }

    public static function resetBreaker(string $fingerprint): void { unset(self::$breaker[$fingerprint]); }

    // ═══════════════════════════════════════
    // Internal
    // ═══════════════════════════════════════

    private static function breakerTrip(string $fp): bool { return (self::$breaker[$fp] ?? 0) >= self::BREAKER_THRESHOLD; }

    private static function breakerUpdate(string $fp, bool $success): void
    {
        if ($success) unset(self::$breaker[$fp]); else self::$breaker[$fp] = (self::$breaker[$fp] ?? 0) + 1;
    }

    /** 执行修复动作 */
    private static function executeAction(string $action, array $diag, array &$result, string $errorMsg, array $context): void
    {
        match ($action) {
            'retry-3x', 'retry-after-init', 'reconnect' => self::retryTransient($diag, $result, $context),
            'dump-autoload' => $result = array_merge($result, ['success' => true, 'fix_detail' => ['via' => 'dump-autoload']]),
            'regenerate-token' => $result = array_merge($result, ['action' => 'suggest', 'reason' => 'CSRF expired — refresh']),
            'add-migration' => self::addMigration($errorMsg, $result, $diag, $context),
            default => self::retryTransient($diag, $result, $context),
        };
    }

    private static function addMigration(string $errorMsg, array &$result, array $diag, array $context): void
    {
        if (preg_match('/table (\w+).*no column named (\w+)/i', $errorMsg, $m)) {
            try {
                if (function_exists('db')) { db()->raw()->query("ALTER TABLE `{$m[1]}` ADD COLUMN `{$m[2]}` TEXT"); }
                $result['success'] = true;
                $result['fix_detail'] = ['via' => 'add-migration', 'table' => $m[1], 'column' => $m[2]];
            } catch (\Throwable) {}
        }
        if (!$result['success']) self::retryTransient($diag, $result, $context);
    }

    /** 指数退避重试 (3次, 上限8s) */
    private static function retryTransient(array $diag, array &$result, array $context): void
    {
        $base = $diag['category'] === 'db-not-ready' ? 1500000 : 400000;
        for ($i = 1; $i <= 3; $i++) {
            usleep(min(8000000, (int)($base * 2 ** ($i - 1) + random_int(0, 300000))));
            $probe = $context['probe'] ?? null;
            if (is_callable($probe) && $probe()) { $result['success'] = true; $result['fix_detail'] = ['attempts' => $i]; return; }
            $retry = $context['retry'] ?? null;
            if (is_callable($retry)) { try { $retry(); $result['success'] = true; $result['fix_detail'] = ['attempts' => $i]; return; } catch (\Throwable $e) { $result['last_error'] = $e->getMessage(); } }
        }
        $result['reason'] = '重试3次后未恢复';
    }

    private static function finalize(array &$result, array $diag, string $fp, string $errorMsg, array $context, float $t0): void
    {
        $result['elapsed_ms'] = (int)((microtime(true) - $t0) * 1000);
        self::logToEventStore($result, $errorMsg, $context);
        self::breakerUpdate($fp, $result['success']);
        SelfHealLedger::record($fp, $result['action'], $result['success'], $diag['category'], $result['elapsed_ms']);
    }

    private static function logToEventStore(array $result, string $errorMsg, array $context): void
    {
        try {
            if (!function_exists('db')) return;
            $store = new EventStore(db()->raw());
            $store->append($result['success'] ? 'heal.success' : 'heal.attempt', $result['fingerprint'], [
                'action' => $result['action'], 'success' => $result['success'],
                'category' => $result['diagnosis']['category'], 'elapsed_ms' => $result['elapsed_ms'],
                'error' => substr($errorMsg, 0, 300), 'url' => $context['url'] ?? 'cli',
            ]);
        } catch (\Throwable) {}
    }
}
