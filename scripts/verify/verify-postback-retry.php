<?php

declare(strict_types=1);

/**
 * verify-postback-retry.php — Phase B: postback RetryHandler 验收 (#51)
 * 独立运行: php verify-postback-retry.php → 期望 PASS=N FAIL=0
 *
 * 覆盖: RetryHandler 退避引擎(成功/失败重试/耗尽) + PostbackDispatcher 接线反射。
 * 关键点: HttpSender 失败返回错误数组不抛异常, sendWithRetry 非2xx主动抛才触发重试。
 */

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/kernel/src/Foundation/Resilience/RetryHandler.php';
}

use Converge\Foundation\Resilience\RetryHandler;

$pass = 0;
$fail = 0;
function check(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name}\n"; }
}

echo "== RetryHandler 退避引擎 (仅抛异常才重试) ==\n";
$rh = new RetryHandler(3, 5); // 5ms base, 快

// 首次成功 → attempts=1, 不重试
$calls = 0;
$r = $rh->execute(function () use (&$calls) { $calls++; return 'ok'; }, 'test');
check('首次成功 → success=true attempts=1', $r['success'] === true && $r['attempts'] === 1 && $r['result'] === 'ok');

// 失败2次后成功 → attempts=3
$calls = 0;
$r = $rh->execute(function () use (&$calls) { $calls++; if ($calls < 3) throw new \RuntimeException('flaky'); return 'ok'; }, 'test');
check('失败2次后成功 → success=true attempts=3', $r['success'] === true && $r['attempts'] === 3 && $r['result'] === 'ok');

// 始终失败 → success=false, attempts=maxRetries+1=4, 带 error
$calls = 0;
$r = $rh->execute(function () use (&$calls) { $calls++; throw new \RuntimeException('boom'); }, 'postback', 'conversion:999');
check('始终失败 → success=false attempts=4', $r['success'] === false && $r['attempts'] === 4);
check('耗尽后 error 含 boom', is_string($r['error']) && str_contains($r['error'], 'boom'));
check('耗尽后 result=null', $r['result'] === null);

// 返回值不抛异常(错误数组语义)不触发重试 → 证实 "必须主动抛" 的设计前提
$calls = 0;
$r = $rh->execute(function () use (&$calls) { $calls++; return ['http_code' => 500, 'error' => 'x']; }, 'test');
check('返回错误数组(不抛)→ 视为成功 attempts=1 (故 sendWithRetry 必须主动抛)', $r['success'] === true && $r['attempts'] === 1);

echo "== PostbackDispatcher 接线反射 (retry 属性 + sendWithRetry 方法) ==\n";
$cls = 'Converge\\Tracking\\PostbackDispatcher';
if (class_exists($cls)) {
    $rc = new \ReflectionClass($cls);
    check('有 retry 属性(注入 RetryHandler)', $rc->hasProperty('retry'));
    check('有 sendWithRetry 私有方法', $rc->hasMethod('sendWithRetry'));
    if ($rc->hasProperty('retry')) {
        $p = $rc->getProperty('retry');
        $t = $p->getType();
        check('retry 属性类型为 RetryHandler', $t !== null && str_contains((string)$t, 'RetryHandler'));
    }
} else {
    echo "  SKIP  PostbackDispatcher 未自动加载(无 autoload), 反射跳过\n";
}

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
