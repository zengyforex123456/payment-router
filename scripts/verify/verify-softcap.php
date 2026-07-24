<?php
// verify-softcap.php — Free 软上限拦 CAPI 纯函数行为证明
// isCapiBlocked(tenantId, overLimit): self-hosted(≤0)永不拦; SaaS租户超限才拦
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use Converge\Tracking\PostbackDispatcher;

$pass = 0; $fail = 0;
function check(string $d, bool $ok): void { global $pass,$fail; echo ($ok?"[PASS] ":"[FAIL] ").$d."\n"; $ok?$pass++:$fail++; }

check('self-hosted(0)+超限 → 不拦(靠License)', PostbackDispatcher::isCapiBlocked(0, true) === false);
check('SaaS租户(5)+未超限 → 不拦', PostbackDispatcher::isCapiBlocked(5, false) === false);
check('SaaS租户(5)+超限 → 拦CAPI', PostbackDispatcher::isCapiBlocked(5, true) === true);
check('负tenant(-1)+超限 → 不拦', PostbackDispatcher::isCapiBlocked(-1, true) === false);

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
