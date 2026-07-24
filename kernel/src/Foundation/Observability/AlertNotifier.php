<?php

declare(strict_types=1);

namespace Converge\Foundation\Observability;

/**
 * AlertNotifier — 🔭 告警推送 (对标 Binom Telegram/Webhook)
 *
 * 多通道告警:
 *   Telegram Bot — 实时推送
 *   Webhook     — HTTP POST 到自定义 URL
 *   Email       — SMTP (复用 PHPMailer)
 *
 * 告警级别:
 *   critical — 系统不可用 (DB down, tracker 宕机)
 *   warning  — 性能下降 (慢查询, 队列堆积)
 *   info     — 信息通知 (优化完成, 新版本)
 *
 * 用法:
 *   $notifier = new AlertNotifier($config);
 *   $notifier->send('Postback failure rate > 10%', 'warning', [
 *       'failures' => 15, 'total' => 100, 'circuit' => 'open'
 *   ]);
 */
class AlertNotifier
{
    private array $config;
    private ?StructuredLogger $logger = null;

    /** 静默期: 同类型告警最小间隔 (秒) */
    private array $cooldowns = [];
    private int $defaultCooldown = 300; // 5分钟

    /** 发送历史 */
    private array $history = [];
    private int $maxHistory = 50;

    public function __construct(
        array $config = [],
        ?StructuredLogger $logger = null,
    ) {
        $this->config = array_merge([
            'telegram_bot_token' => defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '',
            'telegram_chat_id' => defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '',
            'webhook_url' => defined('ALERT_WEBHOOK_URL') ? ALERT_WEBHOOK_URL : '',
            'email_alerts_to' => defined('ALERT_EMAIL') ? ALERT_EMAIL : '',
            'enabled' => true,
        ], $config);
        $this->logger = $logger;
    }

    // ═══════════════════════════════════════
    // 主发送入口
    // ═══════════════════════════════════════

    /**
     * Send an alert through all configured channels.
     *
     * @param string $message  Human-readable alert message
     * @param string $level    critical | warning | info
     * @param array  $context  Additional diagnostic data
     * @param bool   $bypassCooldown  Force send even if in cooldown
     * @return array{channels: array, sent: bool}
     */
    public function send(
        string $message,
        string $level = 'warning',
        array $context = [],
        bool $bypassCooldown = false,
    ): array {
        if (!$this->config['enabled']) {
            return ['channels' => [], 'sent' => false];
        }

        // Cooldown check
        $cooldownKey = md5($level . ':' . substr($message, 0, 50));
        if (!$bypassCooldown && $this->inCooldown($cooldownKey)) {
            $this->log('debug', "Alert suppressed by cooldown: {$message}");
            return ['channels' => [], 'sent' => false, 'cooldown' => true];
        }

        $channels = [];
        $sent = false;

        // Format message
        $formattedMessage = $this->formatMessage($message, $level, $context);

        // Channel 1: Telegram
        if ($this->isConfig('telegram')) {
            $ok = $this->sendTelegram($formattedMessage);
            $channels['telegram'] = $ok;
            $sent = $sent || $ok;
        }

        // Channel 2: Webhook
        if ($this->isConfig('webhook')) {
            $ok = $this->sendWebhook($message, $level, $context);
            $channels['webhook'] = $ok;
            $sent = $sent || $ok;
        }

        // Channel 3: Email (critical only to avoid spam)
        if ($level === 'critical' && $this->isConfig('email')) {
            $ok = $this->sendEmail($message, $level, $context);
            $channels['email'] = $ok;
            $sent = $sent || $ok;
        }

        // Record cooldown
        $this->cooldowns[$cooldownKey] = time();

        // Record history
        $this->history[] = [
            'message' => $message,
            'level' => $level,
            'channels' => $channels,
            'timestamp' => date('c'),
        ];
        if (count($this->history) > $this->maxHistory) {
            array_shift($this->history);
        }

        // Always log
        $this->log($level, "[ALERT] {$message}", $context);

        return [
            'channels' => $channels,
            'sent' => $sent,
        ];
    }

    /**
     * Convenience: critical alert.
     */
    public function critical(string $message, array $context = []): array
    {
        return $this->send($message, 'critical', $context, true); // Bypass cooldown
    }

    /**
     * Convenience: warning alert.
     */
    public function warning(string $message, array $context = []): array
    {
        return $this->send($message, 'warning', $context);
    }

    /**
     * Convenience: info alert.
     */
    public function info(string $message, array $context = []): array
    {
        return $this->send($message, 'info', $context);
    }

    // ═══════════════════════════════════════
    // Channel: Telegram
    // ═══════════════════════════════════════

    private function sendTelegram(string $message): bool
    {
        $token = $this->config['telegram_bot_token'];
        $chatId = $this->config['telegram_chat_id'];

        if (empty($token) || empty($chatId)) {
            return false;
        }

        try {
            $url = "https://api.telegram.org/bot{$token}/sendMessage";

            $payload = json_encode([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $ok = $httpCode === 200;
            if (!$ok) {
                $this->log('error', "Telegram send failed: HTTP {$httpCode}", [
                    'response' => substr((string)$response, 0, 200),
                ]);
            }

            return $ok;
        } catch (\Throwable $e) {
            $this->log('error', "Telegram send exception: " . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════
    // Channel: Webhook
    // ═══════════════════════════════════════

    private function sendWebhook(string $message, string $level, array $context): bool
    {
        $url = $this->config['webhook_url'];
        if (empty($url)) {
            return false;
        }

        try {
            $payload = json_encode([
                'text' => $message,
                'level' => $level,
                'context' => $context,
                'timestamp' => date('c'),
                'source' => 'Converge-AlertNotifier',
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Alert-Level: ' . $level,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode >= 200 && $httpCode < 300;
        } catch (\Throwable $e) {
            $this->log('error', "Webhook send exception: " . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════
    // Channel: Email
    // ═══════════════════════════════════════

    private function sendEmail(string $message, string $level, array $context): bool
    {
        $to = $this->config['email_alerts_to'];
        if (empty($to)) {
            return false;
        }

        try {
            // Use PHPMailer if available (loaded via composer)
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                // Fallback: PHP mail()
                $subject = "[Converge] [{$level}] Alert";
                $body = $message . "\n\nContext:\n" . json_encode($context, JSON_PRETTY_PRINT);
                $headers = "From: tracker@" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
                return mail($to, $subject, $body, $headers);
            }

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = defined('SMTP_HOST') ? SMTP_HOST : 'localhost';
            $mail->SMTPAuth = true;
            $mail->Username = defined('SMTP_USER') ? SMTP_USER : '';
            $mail->Password = defined('SMTP_PASS') ? SMTP_PASS : '';
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
            $mail->setFrom(defined('SMTP_FROM') ? SMTP_FROM : $to, 'Converge Alert');
            $mail->addAddress($to);
            $mail->Subject = "[Converge] [{$level}] Alert";
            $mail->Body = $message . "\n\nContext:\n" . json_encode($context, JSON_PRETTY_PRINT);
            $mail->send();

            return true;
        } catch (\Throwable $e) {
            $this->log('error', "Email alert failed: " . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════

    private function formatMessage(string $message, string $level, array $context): string
    {
        $emojis = [
            'critical' => '🔴',
            'warning' => '🟡',
            'info' => '🔵',
        ];
        $emoji = $emojis[$level] ?? '⚪';

        $host = $_SERVER['HTTP_HOST'] ?? gethostname() ?: 'unknown';
        $time = date('Y-m-d H:i:s');

        $text = "{$emoji} <b>Converge Alert</b> — {$host}\n";
        $text .= "<b>Level:</b> {$level}\n";
        $text .= "<b>Time:</b> {$time}\n\n";
        $text .= htmlspecialchars($message);

        if (!empty($context)) {
            $text .= "\n\n<b>Details:</b>\n";
            foreach (array_slice($context, 0, 10) as $key => $value) {
                $val = is_array($value) ? json_encode($value) : (string)$value;
                $val = substr($val, 0, 200);
                $text .= "  • {$key}: " . htmlspecialchars($val) . "\n";
            }
        }

        return $text;
    }

    private function inCooldown(string $key): bool
    {
        $lastSent = $this->cooldowns[$key] ?? 0;
        return (time() - $lastSent) < $this->defaultCooldown;
    }

    private function isConfig(string $channel): bool
    {
        return match ($channel) {
            'telegram' => !empty($this->config['telegram_bot_token']) && !empty($this->config['telegram_chat_id']),
            'webhook' => !empty($this->config['webhook_url']),
            'email' => !empty($this->config['email_alerts_to']),
            default => false,
        };
    }

    /** @return array */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function setCooldown(int $seconds): void
    {
        $this->defaultCooldown = $seconds;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    // ═══════════════════════════════════════
    // 预定义告警模板
    // ═══════════════════════════════════════

    /**
     * 系统健康告警 — 由 HealthChecker 触发
     */
    public function onHealthDegraded(array $healthResult): void
    {
        foreach ($healthResult['checks'] ?? [] as $component => $check) {
            if (!$check['ok']) {
                $this->critical("Health check FAILED: {$component}", $check);
            }
        }
    }

    /**
     * Circuit breaker opened.
     */
    public function onCircuitOpened(string $name, int $failures): void
    {
        $this->warning("Circuit breaker OPENED: {$name} after {$failures} failures");
    }

    /**
     * Postback failure rate exceeded threshold.
     */
    public function onPostbackFailureRate(float $rate, int $failures, int $total): void
    {
        $pct = round($rate * 100, 1);
        if ($rate > 0.20) {
            $this->critical("Postback failure rate {$pct}% ({$failures}/{$total})");
        } elseif ($rate > 0.10) {
            $this->warning("Postback failure rate {$pct}% ({$failures}/{$total})");
        }
    }

    /**
     * Smart Rotation applied — info notification.
     */
    public function onSmartRotationApplied(int $campaignId, array $changes): void
    {
        if (!empty($changes)) {
            $this->info("Smart Rotation: Campaign #{$campaignId} weights adjusted", $changes);
        }
    }

    /**
     * Bot detected at high confidence.
     */
    public function onBotDetected(string $ip, int $score, array $reasons): void
    {
        if ($score > 80) {
            $this->warning("High-confidence bot: {$ip} (score: {$score})", [
                'reasons' => $reasons,
            ]);
        }
    }
}
