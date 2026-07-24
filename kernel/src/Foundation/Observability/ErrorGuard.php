<?php
/**
 * ErrorGuard — 全局错误捕获 → EventStore + EvidenceChain (🔭 可观察 + 📋 可追溯)
 *
 * 捕获所有未处理异常/错误, 自动:
 *   1. 写入 EventStore (TYPE_ERROR_CAUGHT)
 *   2. 创建 EvidenceChain (test_failure → diagnosis)
 *   3. 提取错误指纹用于后续自愈匹配
 *
 * 用法 (在 bootstrap.php 或 index.php 最顶部):
 *   ErrorGuard::register();
 */
declare(strict_types=1);

namespace Converge\Foundation\Observability;

use Converge\Traceability\Infrastructure\EventStore;
use Converge\Traceability\Infrastructure\EvidenceChain;
use Converge\Traceability\Infrastructure\CausalChain;
use Converge\Foundation\Resilience\SelfHealEngine;

class ErrorGuard
{
    private static bool $registered = false;
    private static array $errorLog = [];
    private static int $maxLogSize = 100;

    /** 注册全局错误处理器 */
    public static function register(): void
    {
        if (self::$registered) return;
        self::$registered = true;

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /** 处理未捕获异常 */
    public static function handleException(\Throwable $e): void
    {
        self::recordError([
            'type'    => 'exception',
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => self::compactTrace($e->getTrace()),
        ]);

        // 创建因果链: symptom → direct cause
        $chain = CausalChain::fromSymptom($e->getMessage(), [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'class' => get_class($e),
        ]);
        $chain->addLayer(CausalChain::LAYER_DIRECT, self::extractDirectCause($e));

        self::persistToEventStore('error.caught', $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile() . ':' . $e->getLine(),
            'causal_chain' => $chain->toArray(),
        ]);

        // 🩺 自愈: 错误→诊断→查账本→自动修复→验证
        try {
            $healResult = SelfHealEngine::attemptHeal($e->getMessage(), [
                'exception' => $e,
                'url'       => $_SERVER['REQUEST_URI'] ?? 'cli',
            ]);
            if ($healResult['action'] !== 'none') {
                error_log('[ErrorGuard] Self-heal: ' . $healResult['action'] . ' → ' . ($healResult['success'] ? 'OK' : 'FAIL'));
            }
        } catch (\Throwable) { /* 自愈失败不影响错误处理 */ }
    }

    /** 处理 PHP 错误 */
    public static function handleError(int $code, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $code)) return false;

        self::recordError([
            'type'    => 'error',
            'code'    => $code,
            'message' => $message,
            'file'    => $file,
            'line'    => $line,
        ]);

        if (in_array($code, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            self::persistToEventStore('error.fatal', $message, ['file' => "$file:$line", 'code' => $code]);
        }

        return false; // 让 PHP 继续默认处理
    }

    /** Shutdown 时捕获 fatal error */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            self::recordError([
                'type'    => 'fatal',
                'message' => $error['message'],
                'file'    => $error['file'],
                'line'    => $error['line'],
            ]);
            self::persistToEventStore('error.fatal', $error['message'], [
                'file' => $error['file'] . ':' . $error['line'],
            ]);
        }

        // Flush to EventStore on shutdown
        if (!empty(self::$errorLog)) {
            self::flushToEventStore();
        }
    }

    /** 获取最近错误 (用于 UI 展示) */
    public static function getRecentErrors(int $limit = 20): array
    {
        return array_slice(self::$errorLog, -$limit);
    }

    /** 错误统计 */
    public static function stats(): array
    {
        $byType = [];
        foreach (self::$errorLog as $e) {
            $t = $e['type'] ?? 'unknown';
            $byType[$t] = ($byType[$t] ?? 0) + 1;
        }
        return ['total' => count(self::$errorLog), 'by_type' => $byType];
    }

    /** 提取错误指纹 (用于自愈匹配) */
    public static function fingerprint(\Throwable $e): string
    {
        $class = get_class($e);
        $msg   = preg_replace('/[0-9]+/', 'N', $e->getMessage());
        $msg   = preg_replace('/\b(0x)?[a-fA-F0-9]{8,}\b/', 'HEX', $msg);
        return 'php|' . strtolower(str_replace('\\', '.', $class)) . '|' . substr(md5($msg), 0, 8);
    }

    // ═══ Internal ═══

    private static function recordError(array $data): void
    {
        $data['timestamp'] = date('c');
        $data['url']       = $_SERVER['REQUEST_URI'] ?? 'cli';
        $data['method']    = $_SERVER['REQUEST_METHOD'] ?? 'CLI';

        self::$errorLog[] = $data;

        // 限制内存使用
        if (count(self::$errorLog) > self::$maxLogSize) {
            self::flushToEventStore();
        }
    }

    private static function persistToEventStore(string $eventType, string $message, array $payload): void
    {
        try {
            if (!class_exists('Converge\Traceability\Infrastructure\EventStore')) return;
            if (!function_exists('db')) return;

            $db = db()->raw();
            $store = new EventStore($db);
            $store->append($eventType, 'system', [
                'message' => $message,
                'payload' => $payload,
                'url'     => $_SERVER['REQUEST_URI'] ?? 'cli',
            ]);
        } catch (\Throwable) {
            // 错误捕获本身不能抛异常
        }
    }

    private static function flushToEventStore(): void
    {
        if (empty(self::$errorLog)) return;
        try {
            if (!function_exists('db')) return;
            $db = db()->raw();
            $store = new EventStore($db);
            foreach (self::$errorLog as $error) {
                $store->append('error.batch', 'system', $error);
            }
            self::$errorLog = [];
        } catch (\Throwable) {}
    }

    private static function compactTrace(array $trace, int $maxFrames = 5): array
    {
        return array_slice(array_map(fn($t) => [
            'file' => ($t['file'] ?? '') . ':' . ($t['line'] ?? ''),
            'function' => ($t['class'] ?? '') . ($t['type'] ?? '') . ($t['function'] ?? ''),
        ], $trace), 0, $maxFrames);
    }

    private static function extractDirectCause(\Throwable $e): string
    {
        $msg = $e->getMessage();

        return match (true) {
            str_contains($msg, 'Class') && str_contains($msg, 'not found') => 'Missing class — composer dump-autoload needed',
            str_contains($msg, 'SQLSTATE') || str_contains($msg, 'mysqli') => 'Database error — check schema or connection',
            str_contains($msg, 'undefined') && str_contains($msg, 'variable') => 'Undefined variable — null guard missing',
            str_contains($msg, 'permission denied') || str_contains($msg, 'Permission') => 'Permission error — check file/dir permissions',
            default => 'Unhandled exception — see trace for details',
        };
    }
}
