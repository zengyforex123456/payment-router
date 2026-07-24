<?php

declare(strict_types=1);

namespace Converge\Foundation\Observability;

/**
 * RequestIdMiddleware — 🔭 可观察
 *
 * Generates a UUID v4 for each request, stores it in $GLOBALS['REQUEST_ID'],
 * and adds X-Request-Id response header for traceability across services.
 */
class RequestIdMiddleware
{
    private string $requestId;

    public function __construct(?string $requestId = null)
    {
        $this->requestId = $requestId ?? self::generateUuid();
    }

    /**
     * Initialize: capture or generate request ID, store globally, set response header.
     */
    public function init(): string
    {
        // Check for incoming request ID from upstream (e.g., load balancer)
        $incoming = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        if ($incoming !== null && $incoming !== '') {
            $this->requestId = $incoming;
        }

        // Store globally for loggers and other components
        $GLOBALS['REQUEST_ID'] = $this->requestId;

        // Set response header (do it now before any output)
        if (!headers_sent()) {
            header("X-Request-Id: {$this->requestId}");
        }

        return $this->requestId;
    }

    /**
     * Get the current request ID.
     */
    public function getId(): string
    {
        return $this->requestId;
    }

    /**
     * Generate a UUID v4.
     */
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        // Set version to 0100 (UUID v4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set variant to 10xx
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
