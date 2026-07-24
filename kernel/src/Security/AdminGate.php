<?php

declare(strict_types=1);

namespace Converge\Security;

use mysqli;
use Converge\Settings\SettingsManager;

/**
 * AdminGate — 管理员 IP 白名单门 (自托管运营方硬化)
 *
 * 挂在 Auth::requireAuth() 顶部 → 一处覆盖所有调 requireAuth 的 admin 页。
 * 5 层防自锁(依次短路), 默认关, 可 storage 文件秒关, 空表 fail-safe。
 *
 * 状态存储 (复用 settings 表, 不建新表):
 *   settings['admin_ip_allowlist_enabled'] = '1' | (缺失/其他 = 关)
 *   settings['admin_ip_allowlist']         = "1.2.3.4, 5.6.7.0/24, ..."
 * 急停: 放 storage/admin_ip_allowlist.disabled 文件 → 秒关(不改 config/不重部署)
 */
class AdminGate
{
    private const KILL_SWITCH = 'admin_ip_allowlist.disabled';
    private const KEY_ENABLED = 'admin_ip_allowlist_enabled';
    private const KEY_LIST    = 'admin_ip_allowlist';

    /**
     * 门本身。命中放行(return); 不命中 → 403 + exit。
     * 命令外壳(FCIS): 读 settings/超全局 + 副作用(403 exit); 判定委托纯函数。
     */
    public static function enforce(mysqli $db): void
    {
        // 1. 部署模式护栏: 仅自托管/单管理员启用; 多租户 SaaS 自动禁用
        //    (否则租户客户同走 requireAuth 会被运营方 IP 门全锁)
        $selfHosted = (defined('SINGLE_ADMIN_MODE') && SINGLE_ADMIN_MODE === true)
            || (defined('DEPLOY_MODE') && DEPLOY_MODE === 'self_hosted');
        if (!$selfHosted) {
            return;
        }

        // 2. 急停文件: 秒关(SSH 丢文件即生效, 独立于 HTTP 通道)
        if (is_file(self::killSwitchPath())) {
            return;
        }

        $settings = new SettingsManager($db);

        // 3. 默认关: 键缺失/≠1 = 允许全部(fresh install 永不锁)
        if ((string) $settings->get(self::KEY_ENABLED, '0') !== '1') {
            return;
        }

        // 4. 空表 fail-safe: 启用但列表空 → 告警 + 放行(绝不把空表当全封)
        $list = self::parseList((string) $settings->get(self::KEY_LIST, ''));
        if ($list === []) {
            error_log('[AdminGate] enabled but allowlist empty → fail-safe allow-all');
            return;
        }

        // 5. 匹配
        $ip = self::clientIp();
        if (self::ipMatches($ip, $list)) {
            return;
        }

        self::block($ip);
    }

    /** 纯函数: 逗号/换行/空格分隔 → 清洗后的条目数组 */
    public static function parseList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $s): bool => $s !== ''
        ));
    }

    /** 纯函数: ip 命中 list 任一条目(精确 或 IPv4 CIDR) */
    public static function ipMatches(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            if ($entry === $ip) {
                return true;
            }
            if (str_contains((string) $entry, '/') && self::cidrMatch($ip, (string) $entry)) {
                return true;
            }
        }
        return false;
    }

    /** 纯函数: IPv4 CIDR 匹配(非 IPv4/非法 → false, 交精确匹配处理) */
    private static function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $maskStr] = array_pad(explode('/', $cidr, 2), 2, '');
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || !is_numeric($maskStr)) {
            return false;
        }
        $mask = (int) $maskStr;
        if ($mask < 0 || $mask > 32) {
            return false;
        }
        if ($mask === 0) {
            return true;
        }
        $maskLong = -1 << (32 - $mask);
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * 可信代理解析器: 默认 REMOTE_ADDR; 仅当 REMOTE_ADDR 是回环/内网(前面有反代)
     * 且存在 X-Real-IP 时才用后者(未来加 CDN 不锁死)。
     * 本服务器 REMOTE_ADDR 为公网 → 直接用, 忽略 X-Real-IP(防伪造)。
     */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (self::isPrivateOrLoopback($remote)) {
            $xReal = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
            if ($xReal !== '') {
                return $xReal;
            }
        }
        return $remote;
    }

    /** 纯函数: ip 属回环/内网/保留段(即前面可能有反代)→ true */
    private static function isPrivateOrLoopback(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private static function block(string $ip): never
    {
        http_response_code(403);
        header('X-Robots-Tag: noindex, nofollow', true);

        if (self::clientExpectsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'Forbidden',
                'reason' => 'ip_not_allowlisted',
                'ip' => $ip,
            ]);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        $safeIp = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>403</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:520px;margin:80px auto;text-align:center">'
            . '<div style="font-size:56px">🔒</div>'
            . '<h2>403 — Access Restricted</h2>'
            . "<p>Your IP <code>{$safeIp}</code> is not on the admin allowlist.</p>"
            . '<p style="color:var(--content-secondary);font-size:13px">Add it with '
            . "<code>php scripts/admin-ip.php add {$safeIp}</code>, "
            . 'or drop <code>storage/admin_ip_allowlist.disabled</code> to disable the gate.</p>'
            . '</body></html>';
        exit;
    }

    /** 复制 Auth::clientExpectsJson 判定模式(该方法为 private, 不可复用) */
    private static function clientExpectsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        return strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
    }

    private static function killSwitchPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/' . self::KILL_SWITCH;
    }
}
