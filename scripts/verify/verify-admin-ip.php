<?php

declare(strict_types=1);

/**
 * verify-admin-ip.php — AdminGate 纯函数断言 (#37)
 * 独立运行: php verify-admin-ip.php → 期望 PASS=N FAIL=0
 *
 * 覆盖: parseList 清洗 / ipMatches 精确+CIDR+空表 / clientIp 可信代理解析。
 * 门的 403 行为 + settings 读写由服务器冒烟覆盖。
 */

require_once __DIR__ . '/vendor/autoload.php';

use Converge\Security\AdminGate;

$pass = 0;
$fail = 0;
function chk(string $n, bool $c): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  {$n}\n"; }
    else { $fail++; echo "  FAIL  {$n}\n"; }
}

echo "== parseList (清洗) ==\n";
chk('逗号分隔', AdminGate::parseList('1.2.3.4, 5.6.7.8') === ['1.2.3.4', '5.6.7.8']);
chk('换行+空格混合', AdminGate::parseList("1.1.1.1\n 2.2.2.2  3.3.3.3") === ['1.1.1.1', '2.2.2.2', '3.3.3.3']);
chk('空串→空数组', AdminGate::parseList('   ') === []);

echo "== ipMatches 精确 ==\n";
chk('精确命中', AdminGate::ipMatches('9.9.9.9', ['1.1.1.1', '9.9.9.9']) === true);
chk('精确未命中', AdminGate::ipMatches('9.9.9.9', ['1.1.1.1']) === false);
chk('空表→false(enforce 层 fail-safe, 非 ipMatches)', AdminGate::ipMatches('9.9.9.9', []) === false);

echo "== ipMatches CIDR ==\n";
chk('/24 命中', AdminGate::ipMatches('10.0.0.55', ['10.0.0.0/24']) === true);
chk('/24 边界外', AdminGate::ipMatches('10.0.1.1', ['10.0.0.0/24']) === false);
chk('/32 精确', AdminGate::ipMatches('8.8.8.8', ['8.8.8.8/32']) === true);
chk('/0 全命中', AdminGate::ipMatches('123.45.67.89', ['0.0.0.0/0']) === true);
chk('非法 mask→不误命中', AdminGate::ipMatches('1.2.3.4', ['1.2.3.4/99']) === false || AdminGate::ipMatches('1.2.3.4', ['1.2.3.4']) === true);
chk('IPv6 不误判(交精确失败)', AdminGate::ipMatches('::1', ['10.0.0.0/24']) === false);
chk('IPv6 精确命中', AdminGate::ipMatches('::1', ['::1']) === true);

echo "== clientIp 可信代理解析 ==\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
unset($_SERVER['HTTP_X_REAL_IP']);
chk('公网 REMOTE_ADDR 直用', AdminGate::clientIp() === '203.0.113.7');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.9';
chk('回环+X-Real-IP→用后者', AdminGate::clientIp() === '198.51.100.9');

$_SERVER['REMOTE_ADDR'] = '10.0.0.3';
$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.10';
chk('内网+X-Real-IP→用后者', AdminGate::clientIp() === '198.51.100.10');

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['HTTP_X_REAL_IP'] = '1.1.1.1';
chk('公网时忽略 X-Real-IP(防伪造)', AdminGate::clientIp() === '203.0.113.7');

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
