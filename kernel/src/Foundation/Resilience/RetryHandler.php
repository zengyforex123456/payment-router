<?php

declare(strict_types=1);

namespace Converge\Foundation\Resilience;

use Converge\Foundation\Observability\StructuredLogger;

/**
 * RetryHandler — 🛡️ 无故障
 *
 * Executes a callable with exponential backoff retry.
 * Primary use case: Postback delivery to affiliate networks.
 *
 * On final failure, writes to dead_letters table if an EventStore is provided.
 */
class RetryHandler
{
    private int $maxRetries;
    private int $baseDelayMs;
    private ?StructuredLogger $logger = null;
    private ?\Converge\Traceability\EventStore $eventStore = null;

    public function __construct(
        int $maxRetries = 3,
        int $baseDelayMs = 1000,
        ?StructuredLogger $logger = null,
        ?\Converge\Traceability\EventStore $eventStore = null
    ) {
        $this->maxRetries = $maxRetries;
        $this->baseDelayMs = $baseDelayMs;
        $this->logger = $logger;
        $this->eventStore = $eventStore;
    }

    /**
     * Execute a callable with retry logic.
     *
     * @template T
     * @param callable(): T $callable
     * @param string $operationName  For logging
     * @param string $aggregateId    For dead letter tracking
     * @return array{success: bool, result: T|null, attempts: int, error: string|null}
     */
    public function execute(callable $callable, string $operationName, string $aggregateId = ''): array
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt <= $this->maxRetries) {
            $attempt++;

            try {
                $result = $callable();
                $this->log('debug', "{$operationName} succeeded on attempt {$attempt}", [
                    'attempt' => $attempt,
                ]);
                return [
                    'success' => true,
                    'result' => $result,
                    'attempts' => $attempt,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->log('warning', "{$operationName} failed (attempt {$attempt}/{$this->maxRetries})", [
                    'error' => $lastError,
                    'attempt' => $attempt,
                ]);

                if ($attempt <= $this->maxRetries) {
                    // Exponential backoff: delay = base * 2^(attempt-1)
                    $delayMs = $this->baseDelayMs * (2 ** ($attempt - 1));
                    // Add jitter: ±25%
                    $jitter = (int)($delayMs * 0.25);
                    $finalDelayMs = $delayMs + random_int(-$jitter, $jitter);

                    usleep(max(0, $finalDelayMs) * 1000);
                }
            }
        }

        // All retries exhausted — write to dead letter
        $this->log('error', "{$operationName} exhausted all retries ({$this->maxRetries})", [
            'error' => $lastError,
            'aggregate_id' => $aggregateId,
        ]);

        if ($this->eventStore && $aggregateId !== '') {
            $this->writeDeadLetter($operationName, $aggregateId, $lastError ?? 'unknown');
        }

        return [
            'success' => false,
            'result' => null,
            'attempts' => $attempt,
            'error' => $lastError,
        ];
    }

    /**
     * Write failed operation to dead_letters table for later retry or manual inspection.
     */
    private function writeDeadLetter(string $eventType, string $aggregateId, string $error): void
    {
        if (!$this->eventStore) {
            return;
        }

        try {
            $this->eventStore->append(
                aggregateId: $aggregateId,
                eventType: "dead_letter:{$eventType}",
                payload: [
                    'error' => $error,
                    'retries' => $this->maxRetries,
                    'max_retries' => $this->maxRetries,
                ]
            );
        } catch (\Throwable $e) {
            // Don't let dead letter recording failure cause more problems
            $this->log('error', "Failed to write dead letter: " . $e->getMessage());
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}
