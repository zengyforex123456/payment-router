<?php
// verify-cryptomus-webhook.php — Cryptomus(USDT) webhook 验签行为证明
// sign = md5(base64(body_sans_sign) + API_KEY)。跑: php verify-cryptomus-webhook.php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

$pass = 0; $fail = 0;
function check(string $d, bool $ok): void { global $pass,$fail; echo ($ok?"[PASS] ":"[FAIL] ").$d."\n"; $ok?$pass++:$fail++; }

$apiKey = 'crypto_test_key';
$rc = new ReflectionClass('Converge\\SaaS\\BillingGate');
$m = $rc->getMethod('verifyCryptomusSignature'); $m->setAccessible(true);
$prop = $rc->getProperty('apiKey'); $prop->setAccessible(true);
$inst = $rc->newInstanceWithoutConstructor();
$prop->setValue($inst, $apiKey);

// 构造合法 payload: 先算 sign 再塞回
$bodyArr = ['status' => 'paid', 'uuid' => 'abc-123', 'amount' => '79'];
$sign = md5(base64_encode(json_encode($bodyArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . $apiKey);
$goodPayload = json_encode(array_merge($bodyArr, ['sign' => $sign]));
$badPayload  = json_encode(array_merge($bodyArr, ['sign' => 'forged123']));
$noSignPayload = json_encode($bodyArr);

check('真签名 → 接受', $m->invoke($inst, $goodPayload) === true);
check('伪造签名 → 拒绝', $m->invoke($inst, $badPayload) === false);
check('缺 sign 字段 → 拒绝', $m->invoke($inst, $noSignPayload) === false);

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
