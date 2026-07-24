<?php
// verify-stripe-webhook.php — Stripe webhook 验签行为证明 (收款安全边界)
// 跑: php verify-stripe-webhook.php → 期望 PASS=3 FAIL=0
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

$pass = 0; $fail = 0;
function check(string $d, bool $ok): void { global $pass,$fail; echo ($ok?"[PASS] ":"[FAIL] ").$d."\n"; $ok?$pass++:$fail++; }

$secret = 'whsec_test123';
$payload = '{"type":"checkout.session.completed"}';
$ts = time();

$rc = new ReflectionClass('Converge\\SaaS\\BillingGate');
$m = $rc->getMethod('verifyStripeSignature'); $m->setAccessible(true);
$prop = $rc->getProperty('webhookSecret'); $prop->setAccessible(true);
$inst = $rc->newInstanceWithoutConstructor();
$prop->setValue($inst, $secret);

$sigGood = "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$payload}", $secret);
$sigBad  = "t={$ts},v1=deadbeef";
$sigOld  = "t=" . ($ts - 400) . ",v1=" . hash_hmac('sha256', ($ts - 400) . ".{$payload}", $secret);

check('真签名 → 接受', $m->invoke($inst, $payload, $sigGood) === true);
check('伪造签名 → 拒绝', $m->invoke($inst, $payload, $sigBad) === false);
check('过期签名(>5min) → 拒绝(防重放)', $m->invoke($inst, $payload, $sigOld) === false);

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
