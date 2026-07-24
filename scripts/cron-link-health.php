<?php
/**
 * cron-link-health.php — Link Health Check Probe (read-only, alert only)
 *
 * Every 5 minutes: curl all LP/Offer URLs, record status, alert on non-200.
 * Crontab: every 5 min via php scripts/cron-link-health.php
 *
 * ⚠️ Does NOT pause campaigns — only alerts via AlertNotifier (Telegram etc).
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

$db = db()->raw();
$notifier = class_exists('Converge\Foundation\Observability\AlertNotifier')
    ? new Converge\Foundation\Observability\AlertNotifier() : null;

echo date('Y-m-d H:i:s') . " — Link Health Check\n";

$checked = 0; $failed = 0; $recovered = 0;

// ═══ Check landing_pages ═══
$lps = $db->query("SELECT id, name, url FROM landing_pages WHERE url IS NOT NULL AND url != ''");
if ($lps) {
    while ($lp = $lps->fetch_assoc()) {
        $result = checkUrl($lp['url']);
        saveResult($db, 'landing_page', (int)$lp['id'], $lp['url'], $result);

        // Check previous status for recovery detection
        $prev = $db->query("SELECT http_code FROM link_health_checks WHERE resource_type='landing_page' AND resource_id={$lp['id']} ORDER BY checked_at DESC LIMIT 1,1");
        $prevCode = $prev ? (int)$prev->fetch_assoc()['http_code'] : 200;

        if ($result['code'] !== 200 && $result['code'] !== 0) {
            $failed++;
            echo "  ⚠️ LP #{$lp['id']} {$lp['name']}: HTTP {$result['code']} ({$result['ms']}ms)\n";
            if ($notifier) {
                $notifier->send(
                    "⚠️ Landing Page #{$lp['id']} '{$lp['name']}' returned HTTP {$result['code']}\nURL: {$lp['url']}",
                    'warning', ['lp_id' => $lp['id'], 'url' => $lp['url'], 'code' => $result['code']]
                );
            }
        } elseif ($prevCode !== 200 && $result['code'] === 200) {
            $recovered++;
            echo "  ✅ LP #{$lp['id']} {$lp['name']}: recovered (HTTP 200)\n";
        }
        $checked++;
    }
}

// ═══ Check offers ═══
$offers = $db->query("SELECT id, name, url FROM offers WHERE url IS NOT NULL AND url != ''");
if ($offers) {
    while ($offer = $offers->fetch_assoc()) {
        $result = checkUrl($offer['url']);
        saveResult($db, 'offer', (int)$offer['id'], $offer['url'], $result);

        $prev = $db->query("SELECT http_code FROM link_health_checks WHERE resource_type='offer' AND resource_id={$offer['id']} ORDER BY checked_at DESC LIMIT 1,1");
        $prevCode = $prev ? (int)$prev->fetch_assoc()['http_code'] : 200;

        if ($result['code'] !== 200 && $result['code'] !== 0) {
            $failed++;
            echo "  ⚠️ Offer #{$offer['id']} {$offer['name']}: HTTP {$result['code']} ({$result['ms']}ms)\n";
            if ($notifier) {
                $notifier->send(
                    "⚠️ Offer #{$offer['id']} '{$offer['name']}' returned HTTP {$result['code']}\nURL: {$offer['url']}",
                    'warning', ['offer_id' => $offer['id'], 'url' => $offer['url'], 'code' => $result['code']]
                );
            }
        } elseif ($prevCode !== 200 && $result['code'] === 200) {
            $recovered++;
            echo "  ✅ Offer #{$offer['id']} {$offer['name']}: recovered (HTTP 200)\n";
        }
        $checked++;
    }
}

echo "  Done: {$checked} checked, {$failed} failed, {$recovered} recovered\n";

// ═══ Helpers ═══

function checkUrl(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_NOBODY => true,
        CURLOPT_USERAGENT => 'Converge-LinkHealth/1.0',
        CURLOPT_SSL_VERIFYPEER => false, // don't block on self-signed certs
    ]);

    $start = microtime(true);
    curl_exec($ch);
    $ms = round((microtime(true) - $start) * 1000);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch) ?: null;
    curl_close($ch);

    return ['code' => $httpCode, 'ms' => $ms, 'error' => $error];
}

function saveResult(\mysqli $db, string $type, int $id, string $url, array $result): void
{
    $stmt = $db->prepare(
        'INSERT INTO link_health_checks (resource_type, resource_id, url, http_code, response_ms, error_message) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $error = $result['error'] ? mb_substr($result['error'], 0, 255) : null;
    $stmt->bind_param('sisdis', $type, $id, $url, $result['code'], $result['ms'], $error);
    $stmt->execute();
    $stmt->close();
}
