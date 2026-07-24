<?php
// verify-affiliate-tier.php — 二级返佣 + 月榜 纯函数验收 (Module G)
// 跑: php verify-affiliate-tier.php → 期望 PASS=N FAIL=0
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use Converge\Modules\SaasReferral\CommissionLedger;

$pass = 0; $fail = 0;
function check(string $d, bool $ok): void { global $pass,$fail; echo ($ok?"[PASS] ":"[FAIL] ").$d."\n"; $ok?$pass++:$fail++; }

// ① 层级费率: tier1=20%, tier2=5%, 其它默认20%
check('tierRate(1)=0.20 直接推荐', CommissionLedger::tierRate(1) === 0.20);
check('tierRate(2)=0.05 二级返佣', CommissionLedger::tierRate(2) === 0.05);
check('tierRate(9)=0.20 未知层级回落默认', CommissionLedger::tierRate(9) === 0.20);

// ② 佣金金额: 二级 5% 计算正确
check('L2 佣金 computeCommission(100,0.05)=5', CommissionLedger::computeCommission(100.0, CommissionLedger::L2_RATE) === 5.0);
check('L2 佣金 computeCommission(79,0.05)=3.95', CommissionLedger::computeCommission(79.0, 0.05) === 3.95);
check('L1 佣金 computeCommission(79,0.20)=15.8', CommissionLedger::computeCommission(79.0, 0.20) === 15.8);

// ③ 月榜奖金梯度: TOP3 有奖, 第4名起 0
check('bonusForRank(1)=100 USDT', CommissionLedger::bonusForRank(1) === 100.0);
check('bonusForRank(2)=50 USDT', CommissionLedger::bonusForRank(2) === 50.0);
check('bonusForRank(3)=25 USDT', CommissionLedger::bonusForRank(3) === 25.0);
check('bonusForRank(4)=0 无奖金', CommissionLedger::bonusForRank(4) === 0.0);
check('bonusForRank(0)=0 未上榜', CommissionLedger::bonusForRank(0) === 0.0);

// ④ 状态机不受二级返佣影响 (回归)
check('状态机 pending→approved 仍合法', CommissionLedger::canTransition('pending', 'approved') === true);
check('状态机 paid→任何 仍拒绝', CommissionLedger::canTransition('paid', 'approved') === false);

// ⑤ 边界: 零/负底数返佣=0 (防脏数据)
check('L2 零底数 → 0', CommissionLedger::computeCommission(0.0, 0.05) === 0.0);
check('L2 负底数 → 0', CommissionLedger::computeCommission(-10.0, 0.05) === 0.0);

echo "\n===== 汇总 =====\nPASS={$pass}  FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
