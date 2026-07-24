<?php
/**
 * SelfHealDiagnose — 错误指纹诊断: 消息→分类→策略桶 (🩺 可自愈)
 * 14种模式映射到4策略桶: transient(60%)·validation(12%)·behavioral(10%)·catastrophic(3%)
 * 用法: $diag = SelfHealDiagnose::diagnose($errorMsg);
 */
declare(strict_types=1);

namespace Converge\Foundation\Resilience;

class SelfHealDiagnose
{
    /** 诊断错误消息 → 返回模式数组 (无匹配时返回 unknown) */
    public static function diagnose(string $errorMsg): array
    {
        $msg = strtolower($errorMsg);
        foreach (SelfHealPatterns::PATTERNS as $p) {
            if (preg_match($p['pattern'], $msg)) return $p;
        }
        return ['category' => 'unknown', 'bucket' => 'transient', 'severity' => 'medium', 'selfHealable' => false, 'note' => '未匹配已知模式'];
    }

    /** category → 4 类桶名 */
    public static function classify(string $category): string
    {
        foreach (SelfHealPatterns::PATTERNS as $p) {
            if ($p['category'] === $category) return $p['bucket'];
        }
        return 'transient';
    }

    /** category → 策略参数 */
    public static function strategyFor(string $category): array
    {
        return SelfHealPatterns::BUCKET_STRATEGY[self::classify($category)];
    }

    /** 提取错误指纹 (稳定特征, 忽略动态参数) */
    public static function fingerprint(string $class, string $message): string
    {
        $msg = preg_replace('/[0-9]+/', 'N', $message);
        $msg = preg_replace('/\b(0x)?[a-fA-F0-9]{8,}\b/', 'HEX', $msg);
        return 'php|' . strtolower(str_replace('\\', '.', $class)) . '|' . substr(md5($msg), 0, 8);
    }

    /** 桶占比 (文档/仪表盘用) */
    public static function bucketShare(): array
    {
        return ['transient' => 0.6, 'validation' => 0.12, 'behavioral' => 0.1, 'catastrophic' => 0.03, 'other' => 0.15];
    }
}
