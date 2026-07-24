<?php
/**
 * SelfHealPatterns — 14 种诊断模式 + 4 策略桶数据 (🩺 data-only, zero logic)
 */
declare(strict_types=1);

namespace Converge\Foundation\Resilience;

class SelfHealPatterns
{
    /** 14 种诊断模式: pattern→category→selfHealable→fix */
    public const PATTERNS = [
        ['pattern' => '/unique constraint failed.*kag_entities/i', 'category' => 'kag-id-collision', 'bucket' => 'transient', 'severity' => 'high', 'selfHealable' => true, 'fix' => 'retry-3x'],
        ['pattern' => '/table.*has no column named/i', 'category' => 'missing-column', 'bucket' => 'validation', 'severity' => 'high', 'selfHealable' => true, 'fix' => 'add-migration'],
        ['pattern' => '/cannot find module|module not found/i', 'category' => 'missing-dependency', 'bucket' => 'catastrophic', 'severity' => 'critical', 'selfHealable' => false, 'action' => 'composer install'],
        ['pattern' => '/db not initialized|call initdb/i', 'category' => 'db-not-ready', 'bucket' => 'transient', 'severity' => 'high', 'selfHealable' => true, 'fix' => 'retry-after-init'],
        ['pattern' => '/syntax\s*error|unexpected token|parse error/i', 'category' => 'syntax-error', 'bucket' => 'validation', 'severity' => 'critical', 'selfHealable' => true, 'fix' => 'fault-clinic-diagnose'],
        ['pattern' => '/connection refused|ECONNREFUSED/i', 'category' => 'network-unavailable', 'bucket' => 'transient', 'severity' => 'high', 'selfHealable' => true, 'fix' => 'retry-3x'],
        ['pattern' => '/timeout|timed out/i', 'category' => 'timeout', 'bucket' => 'transient', 'severity' => 'medium', 'selfHealable' => true, 'fix' => 'retry-3x'],
        ['pattern' => '/permission denied|EACCES/i', 'category' => 'permission', 'bucket' => 'catastrophic', 'severity' => 'critical', 'selfHealable' => false],
        ['pattern' => '/out of memory|OOM/i', 'category' => 'oom', 'bucket' => 'catastrophic', 'severity' => 'critical', 'selfHealable' => false],
        ['pattern' => '/ENOSPC|no space left/i', 'category' => 'disk-full', 'bucket' => 'catastrophic', 'severity' => 'critical', 'selfHealable' => false],
        ['pattern' => '/quality.*failed|gate.*failed/i', 'category' => 'quality-gate', 'bucket' => 'validation', 'severity' => 'medium', 'selfHealable' => true, 'fix' => 'retry-fix-loop'],
        ['pattern' => '/Class.*not found/i', 'category' => 'class-not-found', 'bucket' => 'validation', 'severity' => 'high', 'selfHealable' => true, 'fix' => 'dump-autoload'],
        ['pattern' => '/SQLSTATE|MySQL server has gone away/i', 'category' => 'db-connection-lost', 'bucket' => 'transient', 'severity' => 'high', 'selfHealable' => true, 'fix' => 'reconnect'],
        ['pattern' => '/csurf|CSRF|token.*invalid|token.*mismatch/i', 'category' => 'csrf-invalid', 'bucket' => 'transient', 'severity' => 'medium', 'selfHealable' => true, 'fix' => 'regenerate-token'],
    ];

    /** 4 策略桶参数: retry, backoff, jitter, escalateAfter */
    public const BUCKET_STRATEGY = [
        'transient'     => ['retry' => 3, 'backoff' => 'exponential', 'jitter' => true, 'escalateAfter' => 5],
        'validation'    => ['retry' => 2, 'strategy' => 'fix-and-retry', 'regenerate' => true, 'escalateAfter' => 3],
        'behavioral'    => ['retry' => 0, 'escalate' => true, 'constraint' => 'tighten', 'escalateAfter' => 2],
        'catastrophic'  => ['retry' => 0, 'circuitBreaker' => true, 'escalate' => 'human', 'escalateAfter' => 1],
    ];
}
