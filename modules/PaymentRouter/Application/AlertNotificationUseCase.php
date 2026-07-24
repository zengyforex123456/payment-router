<?php
/**
 * AlertNotificationUseCase — 告警通知推送
 *
 * 支持: Telegram Bot / Slack Webhook / 邮件（扩展点）。
 * 触发条件: B站全部不可用 / B站冷却 / B站恢复 / 成功率低于阈值。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Contracts\DatabaseInterface;

final class AlertNotificationUseCase
{
    private DatabaseInterface $db;
    private array $config;

    public function __construct(DatabaseInterface $db, array $config = [])
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * 发送告警。
     *
     * @param string $level   'critical' | 'warning' | 'info'
     * @param string $title   告警标题
     * @param string $message 告警详情
     * @param array  $channels 覆盖通知渠道（默认使用配置的渠道）
     */
    public function send(string $level, string $title, string $message, array $channels = []): array
    {
        $results = [];
        $targets = $channels ?: ($this->config['channels'] ?? ['telegram']);

        foreach ($targets as $channel) {
            $results[$channel] = match ($channel) {
                'telegram' => $this->sendTelegram($level, $title, $message),
                'slack'    => $this->sendSlack($level, $title, $message),
                'email'    => $this->sendEmail($level, $title, $message),
                'webhook'  => $this->sendGenericWebhook($level, $title, $message),
                default    => false,
            };
        }

        return $results;
    }

    /**
     * 检查并自动触发的告警规则。
     * 建议通过 Cron 每分钟调用。
     */
    public function checkAndAlert(int $tenantId): array
    {
        $alerts = [];

        // 1. 全部 B 站不可用 → critical
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as total,
                    SUM(CASE WHEN status='active' AND daily_order_count < max_daily_orders
                         AND (cooled_until IS NULL OR cooled_until < NOW()) THEN 1 ELSE 0 END) as available
             FROM payment_router_b_sites WHERE tenant_id = ? AND status != 'disabled'"
        );
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ((int)($row['total'] ?? 0) > 0 && (int)($row['available'] ?? 0) === 0) {
            $this->send('critical', '🚨 所有B站不可用', "租户 #{$tenantId}: 所有B站均已冷却或达上限。需要立即处理！");
            $alerts[] = 'all_b_sites_unavailable';
        }

        // 2. 成功率低于阈值 → warning
        $stmt2 = $this->db->prepare(
            "SELECT COUNT(*) as total,
                    SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) as paid
             FROM payment_router_order_mappings
             WHERE tenant_id = ? AND dispatched_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt2->bind_param('i', $tenantId);
        $stmt2->execute();
        $row2 = $stmt2->get_result()->fetch_assoc();
        $total = (int)($row2['total'] ?? 0);
        $paid = (int)($row2['paid'] ?? 0);

        if ($total >= 10) {
            $rate = ($paid / $total) * 100;
            $threshold = $this->config['success_rate_threshold'] ?? 80;
            if ($rate < $threshold) {
                $this->send('warning', '⚠️ 支付成功率下降', "过去1小时成功率: {$paid}/{$total} ({$rate}%), 阈值: {$threshold}%");
                $alerts[] = 'success_rate_low';
            }
        }

        return $alerts;
    }

    // ── 渠道实现 ──

    private function sendTelegram(string $level, string $title, string $message): bool
    {
        $token = $this->config['telegram_bot_token'] ?? '';
        $chatId = $this->config['telegram_chat_id'] ?? '';
        if (!$token || !$chatId) return false;

        $emoji = match ($level) { 'critical' => '🔴', 'warning' => '🟡', default => '🔵' };
        $text = "{$emoji} *{$title}*\n\n{$message}\n\n_".date('Y-m-d H:i:s').'_';

        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code === 200;
    }

    private function sendSlack(string $level, string $title, string $message): bool
    {
        $webhookUrl = $this->config['slack_webhook_url'] ?? '';
        if (!$webhookUrl) return false;

        $color = match ($level) { 'critical' => '#ff0000', 'warning' => '#ffa500', default => '#3b82f6' };

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'attachments' => [[
                    'color' => $color,
                    'title' => $title,
                    'text' => $message,
                    'footer' => 'PaymentRouter',
                    'ts' => time(),
                ]],
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code === 200;
    }

    private function sendEmail(string $level, string $title, string $message): bool
    {
        $to = $this->config['alert_email'] ?? '';
        if (!$to) return false;

        $subject = "[PaymentRouter][{$level}] {$title}";
        return mail($to, $subject, $message);
    }

    private function sendGenericWebhook(string $level, string $title, string $message): bool
    {
        $url = $this->config['alert_webhook_url'] ?? '';
        if (!$url) return false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(compact('level', 'title', 'message')),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }
}
