<?php
// verify-affiliate.php — 联盟归因纯函数行为证明 (模块A)
// 跑: C:\tools\php82\php.exe verify-affiliate.php → 期望 PASS=N FAIL=0
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use Converge\Modules\SaasReferral\ReferralManager;
use Converge\Modules\SaasReferral\CommissionLedger;

$pass = 0; $fail = 0;
function check(string $d, bool $ok): void { global $pass,$fail; echo ($ok?"[PASS] ":"[FAIL] ").$d."\n"; $ok?$pass++:$fail++; }

// normalizeCode: 大写+只留字母数字+截断32
check('normalizeCode 小写→大写', ReferralManager::normalizeCode('abc123') === 'ABC123');
check('normalizeCode 去特殊字符', ReferralManager::normalizeCode('ab-c_1!2') === 'ABC12');
check('normalizeCode 去空格', ReferralManager::normalizeCode('  a b  ') === 'AB');
check('normalizeCode 截断32', strlen(ReferralManager::normalizeCode(str_repeat('x', 50))) === 32);
check('normalizeCode 空→空', ReferralManager::normalizeCode('!!!') === '');

// deriveCode: slug前12 + tenantId
check('deriveCode 正常', ReferralManager::deriveCode(7, 'acme-corp') === 'ACMECORP7');
check('deriveCode 空slug用REF', ReferralManager::deriveCode(9, '') === 'REF9');
check('deriveCode slug截断12', ReferralManager::deriveCode(3, str_repeat('a',20)) === strtoupper(str_repeat('a',12)) . '3');

// computeCommission
check('computeCommission 20% of 79 = 15.8', CommissionLedger::computeCommission(79.0, 0.20) === 15.8);
check('computeCommission 0金额=0', CommissionLedger::computeCommission(0.0, 0.20) === 0.0);
check('computeCommission 0率=0', CommissionLedger::computeCommission(100.0, 0.0) === 0.0);
check('computeCommission 保留2位', CommissionLedger::computeCommission(33.333, 0.15) === 5.0);

// resolveRate: 无tier默认20%, 有tier分层
check('resolveRate 无tier=默认20%', CommissionLedger::resolveRate(50) === 0.20);
$tiers = [[0, 0.10], [5, 0.15], [20, 0.20]];
check('resolveRate 3单→10%', CommissionLedger::resolveRate(3, $tiers) === 0.10);
check('resolveRate 10单→15%', CommissionLedger::resolveRate(10, $tiers) === 0.15);
check('resolveRate 25单→20%', CommissionLedger::resolveRate(25, $tiers) === 0.20);

// canTransition 状态机
check('canTransition pending→approved', CommissionLedger::canTransition('pending','approved') === true);
check('canTransition approved→paid', CommissionLedger::canTransition('approved','paid') === true);
check('canTransition pending→paid 非法', CommissionLedger::canTransition('pending','paid') === false);
check('canTransition paid→任何 非法', CommissionLedger::canTransition('paid','reversed') === false);
check('canTransition pending→reversed', CommissionLedger::canTransition('pending','reversed') === true);

// isValidWallet: TRC-20(T+33) / EVM(0x+40)
check('钱包 TRC-20 合法', ReferralManager::isValidWallet('TN3W4H6rK2ce4vX9YnFQHwKENnHjoxb3m9', 'trc20') === true);
check('钱包 TRC-20 短一位→拒', ReferralManager::isValidWallet('TN3W4H6rK2ce4vX9YnFQHwKENnHjoxb3m', 'trc20') === false);
check('钱包 EVM bep20 合法', ReferralManager::isValidWallet('0x'.str_repeat('a',40), 'bep20') === true);
check('钱包 EVM 非hex→拒', ReferralManager::isValidWallet('0x'.str_repeat('z',40), 'bep20') === false);
check('钱包 TRC地址填EVM网络→拒', ReferralManager::isValidWallet('TN3W4H6rK2ce4vX9YnFQHwKENnHjoxb3m9', 'bep20') === false);
check('钱包 未知网络→拒', ReferralManager::isValidWallet('0x'.str_repeat('a',40), 'btc') === false);
check('钱包 空→拒', ReferralManager::isValidWallet('', 'trc20') === false);

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
