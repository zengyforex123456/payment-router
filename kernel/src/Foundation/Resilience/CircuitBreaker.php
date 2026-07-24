<?php

declare(strict_types=1);

namespace Converge\Foundation\Resilience;

use Converge\Foundation\Observability\StructuredLogger;

/**
 * CircuitBreaker — 🛡️ 无故障
 *
 * Prevents cascading failures by wrapping external API calls.
 * States: CLOSED (normal) → OPEN (failing, fast-fail) → HALF_OPEN (testing recovery)
 *
 * Usage:
 *   $cb = new CircuitBreaker('facebook_api', 3, 30);
 *   $result = $cb->execute(fn() => $facebookClient->fetchCosts($adAccount));
 */
class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';
    private const REDIS_KEY_PREFIX = 'cb:';
    private const REDIS_TTL = 3600; // 1 hour persistence

    private string $name;
    private int $failureThreshold;
    private int $resetTimeoutSeconds;
    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private ?int $lastFailureTime = null;
    private ?StructuredLogger $logger = null;
    /** @var mixed Redis connection or null for in-memory-only mode */
    private mixed $redis = null;

    public function __construct(
        string $name,
        int $failureThreshold = 3,
        int $resetTimeoutSeconds = 30,
        ?StructuredLogger $logger = null,
        mixed $redis = null,  // Redis connection (Predis\Client or Redis)
    ) {
        $this->name = $name;
        $this->failureThreshold = $failureThreshold;
        $this->resetTimeoutSeconds = $resetTimeoutSeconds;
        $this->logger = $logger;
        $this->redis = $redis;
        $this->loadFromRedis();
    }

    /**
     * Execute a callable with circuit breaker protection.
     *
     * @template T
     * @param callable(): T $callable
     * @param callable(): T|null $fallback  Called when circuit is open
     * @return T|null
     * @throws \RuntimeException When circuit is open and no fallback provided
     */
    public function execute(callable $callable, ?callable $fallback = null): mixed
    {
        // Check if circuit is open
        if ($this->state === self::STATE_OPEN) {
            if ($this->shouldAttemptReset()) {
                $this->state = self::STATE_HALF_OPEN;
                $this->log('info', "Circuit half-open, testing recovery");
            } else {
                $this->log('warning', "Circuit open, fast-failing");
                if ($fallback) {
                    return $fallback();
                }
                throw new \RuntimeException("Circuit breaker [{$this->name}] is OPEN");
            }
        }

        try {
            $result = $callable();

            // Success: reset on success in half-open state
            if ($this->state === self::STATE_HALF_OPEN) {
                $this->reset();
                $this->log('info', "Circuit closed, service recovered");
            }

            // Reset failure count on any success
            if ($this->failureCount > 0) {
                $this->failureCount = 0;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure();
            $this->log('error', "Circuit breaker failure: " . $e->getMessage(), [
                'exception' => get_class($e),
                'failure_count' => $this->failureCount,
                'state' => $this->state,
            ]);

            if ($fallback) {
                return $fallback();
            }

            throw $e;
        }
    }

    /**
     * Get current state for monitoring.
     */
    public function getState(): array
    {
        return [
            'name' => $this->name,
            'state' => $this->state,
            'failure_count' => $this->failureCount,
            'last_failure_at' => $this->lastFailureTime ? date('c', $this->lastFailureTime) : null,
        ];
    }

    /**
     * Force reset the circuit (for admin/testing).
     */
    public function forceReset(): void
    {
        $this->reset();
        $this->log('info', "Circuit manually reset");
    }

    private function shouldAttemptReset(): bool
    {
        if ($this->lastFailureTime === null) {
            return true;
        }
        return (time() - $this->lastFailureTime) >= $this->resetTimeoutSeconds;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $context['circuit'] = $this->name;
            $this->logger->log($level, $message, $context);
        }
    }

    // ═══ Redis Persistence (survives container restarts) ═══

    private function redisKey(): string
    {
        return self::REDIS_KEY_PREFIX . $this->name;
    }

    private function loadFromRedis(): void
    {
        if (!$this->redis) return;
        try {
            $raw = $this->redis->get($this->redisKey());
            if (!$raw) return;
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) return;
            $this->state = $data['state'] ?? self::STATE_CLOSED;
            $this->failureCount = (int)($data['failure_count'] ?? 0);
            $this->lastFailureTime = $data['last_failure_time'] ?? null;
        } catch (\Throwable $e) {
            // Redis unavailable: silent fallback to in-memory state
        }
    }

    private function persistToRedis(): void
    {
        if (!$this->redis) return;
        try {
            $this->redis->setex($this->redisKey(), self::REDIS_TTL, json_encode([
                'state' => $this->state,
                'failure_count' => $this->failureCount,
                'last_failure_time' => $this->lastFailureTime,
            ]));
        } catch (\Throwable $e) {
            // Redis unavailable: silent fallback
        }
    }

    private function reset(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->lastFailureTime = null;
        $this->persistToRedis();
    }

    private function recordFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = time();

        if ($this->failureCount >= $this->failureThreshold) {
            $this->state = self::STATE_OPEN;
            $this->log('alert', "Circuit opened after {$this->failureCount} failures");
        }
        $this->persistToRedis();
    }
}
