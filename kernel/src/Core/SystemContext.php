<?php

declare(strict_types=1);

namespace Converge\Core;

/**
 * SystemContext — 全局追踪上下文 (六维模型的中枢神经)
 *
 * 携带: TraceId (全链路) · SessionUserId (操作者) · RequestStartTime (计时)
 * 注入: Logger · SelfEvolver · KagClient · EventStore
 *
 * 用法:
 *   $ctx = SystemContext::init($_SERVER);
 *   $ctx->log('info', 'Module loaded', ['module' => 'Campaign']);
 *   $kag->capture('修复模式', '...', [...], ['tag'], 'seed', $ctx->traceId());
 *
 * 六维对应:
 *   可观察 → metrics()  可追溯 → traceId()  可审计 → auditEntry()
 */
class SystemContext
{
    private static ?self $instance = null;

    private string $traceId;
    private string $sessionUserId;
    private string $clientIp;
    private float $requestStartTime;
    private string $requestUri;
    private string $requestMethod;
    private array $metrics = [];
    private array $auditLog = [];

    private function __construct()
    {
        $this->traceId = bin2hex(random_bytes(12));
        $this->requestStartTime = microtime(true);
    }

    /** Initialize from request context */
    public static function init(?array $server = null): self
    {
        if (self::$instance) return self::$instance;

        $ctx = new self();
        $server = $server ?? $_SERVER;

        $ctx->clientIp = $server['REMOTE_ADDR']
            ?? $server['HTTP_X_FORWARDED_FOR']
            ?? $server['HTTP_X_REAL_IP']
            ?? '127.0.0.1';

        $ctx->requestUri = $server['REQUEST_URI'] ?? 'cli';
        $ctx->requestMethod = $server['REQUEST_METHOD'] ?? 'CLI';

        // Session user from Auth if available
        $ctx->sessionUserId = $_SESSION['user_id']
            ?? $_SESSION['auth_user_id']
            ?? 'system';

        self::$instance = $ctx;
        return $ctx;
    }

    /** Get or create singleton */
    public static function get(): self
    {
        return self::$instance ?? self::init();
    }

    /** Reset for testing */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // ═══ Getters ═══

    public function traceId(): string { return $this->traceId; }
    public function sessionUserId(): string { return $this->sessionUserId; }
    public function clientIp(): string { return $this->clientIp; }
    public function requestUri(): string { return $this->requestUri; }
    public function requestMethod(): string { return $this->requestMethod; }
    public function elapsedMs(): int { return (int)((microtime(true) - $this->requestStartTime) * 1000); }

    /** Set session user (call after Auth verification) */
    public function setSessionUser(string $userId): void
    {
        $this->sessionUserId = $userId;
    }

    // ═══ 六维集成 ═══

    /**
     * 🔭 可观察: 记录运行时指标
     */
    public function metric(string $name, float $value, array $tags = []): void
    {
        $this->metrics[] = [
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
            'ts' => microtime(true),
        ];
    }

    /** Get current metrics snapshot */
    public function metrics(): array
    {
        return [
            'trace_id' => $this->traceId,
            'elapsed_ms' => $this->elapsedMs(),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
            'metrics' => $this->metrics,
        ];
    }

    /**
     * 📋 可追溯: 全链路日志
     */
    public function log(string $level, string $message, array $context = []): array
    {
        $entry = [
            'trace_id' => $this->traceId,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => date('c'),
            'elapsed_ms' => $this->elapsedMs(),
        ];

        // Structured output (JSON line)
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log("[CONVERGE] {$line}");

        return $entry;
    }

    /**
     * 📐 可审计: 不可变审计记录
     *
     * @param string $action  操作类型 (e.g. 'module.loaded', 'selfheal.executed')
     * @param string $target  操作对象 (e.g. 'Campaign', 'kag.fingerprint.NNN')
     * @param string $result  操作结果 ('success'|'failure'|'partial')
     * @param array  $detail  详细数据
     */
    public function audit(string $action, string $target, string $result, array $detail = []): array
    {
        $entry = [
            // 审计三要素: 谁·何时·做了什么
            'who' => [
                'user_id' => $this->sessionUserId,
                'ip' => $this->clientIp,
            ],
            'when' => date('c'),
            'what' => [
                'action' => $action,
                'target' => $target,
                'result' => $result,
            ],
            // 六维绑定
            'trace_id' => $this->traceId,
            'request_uri' => $this->requestUri,
            'request_method' => $this->requestMethod,
            // 证据
            'detail' => $detail,
            'elapsed_ms' => $this->elapsedMs(),
        ];

        $this->auditLog[] = $entry;
        return $entry;
    }

    /** Get all audit entries for this request */
    public function auditEntries(): array
    {
        return $this->auditLog;
    }

    /**
     * 审计日志 OODA 标准格式 (JSON Schema)
     *
     * 方便导入 ElasticSearch / Kibana 做可视化:
     * {
     *   "@timestamp": "2026-07-17T01:00:00Z",
     *   "trace_id": "a1b2c3...",
     *   "ooda_phase": "act|observe|orient|decide",
     *   "actor": "SelfEvolver|ShadowMode|EvolutionEngine|Human",
     *   "action": "promote_fingerprint|shadow_graduate|apply_fix",
     *   "target": "kag.entity.NNN|template.pages.register",
     *   "result": "success|failure|skipped",
     *   "detail": {...},
     *   "user_id": "system|admin@converge.io",
     *   "client_ip": "10.0.0.1",
     *   "elapsed_ms": 42
     * }
     */
    public function oodaAudit(string $oodaPhase, string $actor, string $action, string $target, string $result, array $detail = []): array
    {
        return $this->audit("ooda.{$oodaPhase}.{$actor}.{$action}", $target, $result, array_merge($detail, [
            '@timestamp' => date('c'),
            'ooda_phase' => $oodaPhase,
            'actor' => $actor,
            'action' => $action,
        ]));
    }
}
