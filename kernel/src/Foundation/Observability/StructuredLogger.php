<?php

declare(strict_types=1);

namespace Converge\Foundation\Observability;

/**
 * StructuredLogger — 🔭 可观察
 *
 * PSR-3 compatible structured JSON logger.
 * Replaces the single-purpose Logger.php with a general logger.
 * Log levels: emergency, alert, critical, error, warning, notice, info, debug.
 */
class StructuredLogger
{
    public const EMERGENCY = 'emergency';
    public const ALERT = 'alert';
    public const CRITICAL = 'critical';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTICE = 'notice';
    public const INFO = 'info';
    public const DEBUG = 'debug';

    private const LEVELS = [
        self::EMERGENCY => 0,
        self::ALERT => 1,
        self::CRITICAL => 2,
        self::ERROR => 3,
        self::WARNING => 4,
        self::NOTICE => 5,
        self::INFO => 6,
        self::DEBUG => 7,
    ];

    private string $logDir;
    private string $logFile;
    private string $minLevel;
    private int $retentionDays;

    public function __construct(
        ?string $logDir = null,
        string $logFile = 'app.log',
        string $minLevel = 'debug',
        int $retentionDays = 7
    ) {
        $this->logDir = $logDir ?? (defined('LOGS_PATH') ? LOGS_PATH : sys_get_temp_dir());
        $this->logFile = rtrim($this->logDir, '/') . '/' . $logFile;
        $this->minLevel = $minLevel;
        $this->retentionDays = $retentionDays;

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Log a message at any level.
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $requestId = $context['request_id'] ?? ($GLOBALS['REQUEST_ID'] ?? null);
        if ($requestId !== null) {
            $entry['request_id'] = $requestId;
        }

        file_put_contents(
            $this->logFile,
            json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(self::ALERT, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * Check if this level should be logged based on configured min level.
     */
    private function shouldLog(string $level): bool
    {
        $levelValue = self::LEVELS[$level] ?? 999;
        $minValue = self::LEVELS[$this->minLevel] ?? 7;
        return $levelValue <= $minValue;
    }

    /**
     * Rotate logs: remove entries older than retention period.
     */
    public function rotate(): int
    {
        if (!is_file($this->logFile)) {
            return 0;
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return 0;
        }

        $cutoff = time() - ($this->retentionDays * 86400);
        $kept = [];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry && isset($entry['timestamp'])) {
                $ts = strtotime($entry['timestamp']);
                if ($ts !== false && $ts >= $cutoff) {
                    $kept[] = $line;
                }
            }
        }

        $deleted = count($lines) - count($kept);
        if ($deleted > 0) {
            file_put_contents($this->logFile, implode("\n", $kept) . "\n", LOCK_EX);
        }

        return $deleted;
    }
}
