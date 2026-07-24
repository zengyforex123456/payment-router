<?php
// verify-affiliate-payout.php — 批量代付纯函数证明 (模块D)
// dedupeRecipients: 去重合并同址金额, 防重复付款。跑: php verify-affiliate-payout.php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use Converge\Modules\SaasReferral\BillingGate;

$pass = 0; $fail = 0;
function check(string $d, bool $ok): void { global $pass,$fail; echo ($ok?"[PASS] ":"[FAIL] ").$d."\n"; $ok?$pass++:$fail++; }

// 同址两笔 → 合并为一, 金额相加
$r = BillingGate::dedupeRecipients([
    ['wallet'=>'TAAA', 'network'=>'trc20', 'amount'=>10.0, 'commission_id'=>1],
    ['wallet'=>'TAAA', 'network'=>'trc20', 'amount'=>5.5,  'commission_id'=>2],
    ['wallet'=>'TBBB', 'network'=>'trc20', 'amount'=>20.0, 'commission_id'=>3],
]);
check('去重: 2个唯一钱包', count($r) === 2);
check('同址金额合并 10+5.5=15.5', $r['TAAA']['amount'] === 15.5);
check('同址 ids 合并', $r['TAAA']['ids'] === [1,2]);
check('不同址独立', $r['TBBB']['amount'] === 20.0);

// 脏数据过滤
$r2 = BillingGate::dedupeRecipients([
    ['wallet'=>'', 'amount'=>10.0],        // 空址
    ['wallet'=>'TCCC', 'amount'=>0],       // 0金额
    ['wallet'=>'TCCC', 'amount'=>-5],      // 负金额
    ['wallet'=>'TDDD', 'network'=>'trc20', 'amount'=>8.0],
]);
check('空址/0/负金额被过滤', count($r2) === 1 && isset($r2['TDDD']));
check('空输入→空', BillingGate::dedupeRecipients([]) === []);

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
