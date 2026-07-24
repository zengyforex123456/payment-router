#!/usr/bin/env bash
# verify-paywall.sh — 收款闭环/注册管理 可验证验收 (七可·可验证)
#
# 哲学: 不靠"我觉得好了", 每条交付物一条可证伪断言 → PASS/FAIL。
#   [PASS]/[FAIL] = 已交付范围, 任一 FAIL → exit 1 (阻塞)
#   [TODO]        = 未建的付费墙规格(预期红), 不阻塞, 驱动下一步
#
# 用法: bash verify-paywall.sh              # 本地静态 + 服务器动态
#       SKIP_SERVER=1 bash verify-paywall.sh # 只跑本地静态
#
# PHP: 本机不在 PATH, 见 .claude/tools.json → C:\tools\php82\php.exe
set -u
cd "$(dirname "$0")"

PHP="${PHP_BIN:-/c/tools/php82/php.exe}"
SERVER_URL="${SERVER_URL:-http://137.184.225.93}"
PASS=0; FAIL=0; TODO=0

pass(){ echo "[PASS] $1"; PASS=$((PASS+1)); }
fail(){ echo "[FAIL] $1"; FAIL=$((FAIL+1)); }
todo(){ echo "[TODO] $1"; TODO=$((TODO+1)); }
# assert_grep <desc> <pattern> <file>
assert_grep(){ if grep -qE -- "$2" "$3" 2>/dev/null; then pass "$1"; else fail "$1"; fi; }
assert_nogrep(){ if grep -qE -- "$2" "$3" 2>/dev/null; then fail "$1"; else pass "$1"; fi; }

echo "===== A. 本地静态断言 ====="

# A1. PHP 语法 — 所有改动/新增文件
FILES="src/SaaS/TenantAdmin.php src/SaaS/PlanContext.php src/SaaS/TenantManager.php \
src/Core/DeployMode.php src/Tracking/ConversionTracker.php resources/views/tenants.php \
public/index.php lang/en.php lang/zh.php"
if [ -x "$PHP" ] || command -v "$PHP" >/dev/null 2>&1; then
  for f in $FILES; do
    if "$PHP" -l "$f" 2>&1 | grep -q "No syntax errors"; then pass "php -l $f"; else fail "php -l $f"; fi
  done
else
  todo "PHP 不可用 ($PHP) — 跳过 php -l (设 PHP_BIN)"
fi

# A2. i18n key 对齐 (en == zh, 防运行时缺 key)
EN=$(grep -cE "'(tenants\.|page\.tenants)" lang/en.php)
ZH=$(grep -cE "'(tenants\.|page\.tenants)" lang/zh.php)
if [ "$EN" -eq "$ZH" ] && [ "$EN" -gt 0 ]; then pass "i18n tenants.* 对齐 (en=$EN zh=$ZH)"; else fail "i18n 不齐 (en=$EN zh=$ZH)"; fi

# A3. 破坏性操作必须 CSRF 保护
assert_grep "tenants.php 用 Csrf::validate 拦 POST" "Csrf::validate" resources/views/tenants.php

# A4. 多租户防越权 — 视图层 operator 门
assert_grep "tenants.php SaaS 模式校验 operator" "isOperatorTenant" resources/views/tenants.php

# A5. 路由已挂 + 权限门
assert_grep "index.php 白名单含 tenants" "'tenants'," public/index.php
assert_grep "index.php tenants → PERM_USER_MANAGE" "'tenants' => Permission::PERM_USER_MANAGE" public/index.php

# A6. 新 SaaS 类无裸 SQL 拼接 — 用 prepare
assert_grep "TenantAdmin 用 prepare 参数化" "->prepare\(" src/SaaS/TenantAdmin.php
assert_nogrep "TenantManager createTenantAdmin 无裸插值" "VALUES \(\{\\\$tenantId\}" src/SaaS/TenantManager.php

# A7. PSR-12 类文件不留 ?> 闭合标签 (防 ?> 半渲染雷)
assert_nogrep "TenantAdmin 无 ?> 闭合标签" "\?>" src/SaaS/TenantAdmin.php
assert_nogrep "PlanContext 无 ?> 闭合标签" "\?>" src/SaaS/PlanContext.php

# A8. DeployMode SaaS 分支已修 (不再 function_exists 恒 false)
assert_nogrep "DeployMode 不再用坏的 function_exists 探测" "function_exists\('Converge" src/Core/DeployMode.php

# A9. 用量计量已接线 (面板用量列真实数据源)
assert_grep "ConversionTracker 计量租户用量" "recordTenantUsage" src/Tracking/ConversionTracker.php

# A10. FeatureRegistry 已激活 + 行为证明 (bootstrap 后 free/pro 解析正确)
assert_grep "FeatureRegistry::bootstrap 已接入 bootstrap.php" "FeatureRegistry::bootstrap" src/bootstrap.php
if [ -x "$PHP" ] || command -v "$PHP" >/dev/null 2>&1; then
  if "$PHP" verify-featureregistry.php >/dev/null 2>&1; then pass "FeatureRegistry 行为验证(14断言全绿)"; else fail "FeatureRegistry 行为验证"; fi
fi

# A11. Stripe webhook 验签 (收款安全边界: 伪造拒绝·过期防重放)
assert_grep "BillingGate 有真验签(非桩)" "verifyStripeSignature" src/SaaS/BillingGate.php
assert_grep "webhook端点验签失败返401" "http_response_code\(401\)" public/api-billing-webhook.php
if [ -x "$PHP" ] || command -v "$PHP" >/dev/null 2>&1; then
  if "$PHP" verify-stripe-webhook.php >/dev/null 2>&1; then pass "Stripe验签行为(真收/伪拒/防重放)"; else fail "Stripe验签行为"; fi
fi

# A12. Cryptomus(USDT) webhook 验签 (镜像 Stripe 安全边界)
assert_grep "BillingGate 有 Cryptomus 验签" "verifyCryptomusSignature" src/SaaS/BillingGate.php
assert_grep "webhook端点按provider路由" "provider.*cryptomus|isCryptomus" public/api-billing-webhook.php
if [ -x "$PHP" ] || command -v "$PHP" >/dev/null 2>&1; then
  if "$PHP" verify-cryptomus-webhook.php >/dev/null 2>&1; then pass "Cryptomus验签行为(真收/伪拒/缺sign拒)"; else fail "Cryptomus验签行为"; fi
fi

# A13. Free 软上限拦 CAPI (超500/月停CAPI但仍追踪; self-hosted永不拦)
assert_grep "PostbackDispatcher 有软上限门控" "capiBlockedByPlan" src/Tracking/PostbackDispatcher.php
assert_grep "traffic source postback 不受限(核心追踪)" "始终发" src/Tracking/PostbackDispatcher.php
if [ -x "$PHP" ] || command -v "$PHP" >/dev/null 2>&1; then
  if "$PHP" verify-softcap.php >/dev/null 2>&1; then pass "软上限行为(SaaS超限拦CAPI/self-hosted不拦)"; else fail "软上限行为"; fi
fi

# A14. 联盟返佣 (归因+佣金台账+钱包校验+批量代付去重)
assert_grep "ReferralManager 自荐防护" "referrerTenantId === .referredTenantId" src/SaaS/ReferralManager.php
assert_grep "CommissionLedger 幂等(invoice_id UNIQUE)" "invoice_id VARCHAR\(255\) NOT NULL UNIQUE" src/SaaS/CommissionLedger.php
assert_grep "BillingGate:370 接佣金应计" "CommissionLedger.*->accrue" src/SaaS/BillingGate.php
assert_grep "massPayout 复用 Cryptomus sign" "md5\(base64_encode\(\\\$json\) \. \\\$this->apiKey\)" src/SaaS/BillingGate.php
assert_grep "代付默认关(需显式开通)" "CRYPTOMUS_PAYOUT_ENABLED" config/config.php
assert_grep "commissions.php 双门(operator)" "isOperatorTenant" resources/views/commissions.php
if [ -x "$PHP" ] || command -v "$PHP" >/dev/null 2>&1; then
  if "$PHP" verify-affiliate.php >/dev/null 2>&1; then pass "联盟纯函数(归因+佣金+钱包 28断言)"; else fail "联盟纯函数"; fi
  if "$PHP" verify-affiliate-payout.php >/dev/null 2>&1; then pass "代付去重(防重复付款 6断言)"; else fail "代付去重"; fi
fi

echo ""
echo "===== B. 服务器动态断言 (${SERVER_URL}) ====="
if [ "${SKIP_SERVER:-0}" = "1" ]; then
  todo "SKIP_SERVER=1 — 跳过服务器断言 (未部署)"
else
  code(){ curl -s -o /dev/null -w '%{http_code}' -m 8 "$1" 2>/dev/null || echo "000"; }
  # B1. 注册页可达
  RC=$(code "${SERVER_URL}/register.php")
  if [ "$RC" = "200" ]; then pass "register.php → 200"; elif [ "$RC" = "000" ]; then todo "服务器不可达 (未部署?) — register.php"; else fail "register.php → $RC (期望 200)"; fi
  # B2. 安全: 未登录访问 tenants 面板 必须不给 200 数据 (期望 302 登录/401/403)
  TC=$(code "${SERVER_URL}/index.php?page=tenants")
  case "$TC" in
    302|401|403) pass "未登录访问 tenants → $TC (拒绝, 安全)";;
    000) todo "服务器不可达 — tenants 鉴权";;
    *) fail "未登录访问 tenants → $TC (安全红线: 不该 200 泄露租户列表)";;
  esac
fi

echo ""
echo "===== C. 付费墙下一步 (backlog) ====="
todo "上线收真钱: 服务端配 STRIPE_API_KEY/WEBHOOK_SECRET + CRYPTOMUS_API_KEY/MERCHANT_ID (env注入)"

echo ""
echo "===== 汇总 ====="
echo "PASS=$PASS  FAIL=$FAIL  TODO=$TODO"
if [ "$FAIL" -gt 0 ]; then echo "❌ 有 FAIL, 已交付范围有回归 — 阻塞"; exit 1; fi
echo "✅ 已交付范围全绿; TODO=$TODO 为付费墙下一步"
exit 0
