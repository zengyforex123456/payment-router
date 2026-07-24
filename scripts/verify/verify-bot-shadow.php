<?php

declare(strict_types=1);

/**
 * verify-bot-shadow.php — Phase C: bot 影子模式接线验收 (#51)
 * 独立运行: php verify-bot-shadow.php → 期望 PASS=N FAIL=0
 *
 * 覆盖(反射): BotDetector reverseDns 开关 + Redirector 影子接线(方法/属性)。
 * analyze 真实延迟 + extra_json 标记由服务器冒烟覆盖(需 DB)。
 */

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/app/Security/BotDetector.php';
}

$pass = 0;
$fail = 0;
function check(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name}\n"; }
}

echo "== BotDetector reverseDns 开关 (禁反向DNS防热路径延迟) ==\n";
$rc = new \ReflectionClass('Converge\\Security\\BotDetector');
$ctor = $rc->getConstructor();
$params = $ctor->getParameters();
check('构造有 4 个参数(db,eventStore,logger,reverseDns)', count($params) === 4);
$p4 = $params[3] ?? null;
check('第4参名为 reverseDns', $p4 !== null && $p4->getName() === 'reverseDns');
check('reverseDns 默认 true(向后兼容旧调用)', $p4 !== null && $p4->isDefaultValueAvailable() && $p4->getDefaultValue() === true);

echo "== Redirector 影子接线 ==\n";
$cls = 'Converge\\Tracking\\Redirector';
if (class_exists($cls)) {
    $rr = new \ReflectionClass($cls);
    check('有 runBotShadow 私有方法', $rr->hasMethod('runBotShadow'));
    check('有 shadowMarkers 属性', $rr->hasProperty('shadowMarkers'));
    if ($rr->hasMethod('runBotShadow')) {
        $m = $rr->getMethod('runBotShadow');
        check('runBotShadow 为 private(仅内部影子)', $m->isPrivate());
    }
} else {
    echo "  SKIP  Redirector 未自动加载(无 autoload), 反射跳过\n";
}

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
