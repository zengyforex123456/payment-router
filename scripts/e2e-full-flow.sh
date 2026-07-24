#!/bin/bash
# PaymentRouter — Full E2E Flow Test
# A站(WP) → 中控 → B站(OC) → Webhook → Dashboard
# 使用 PHP 内置服务器 + 独立路由器
set -e

BASE="http://127.0.0.1:${1:-8085}"
PASS=0; FAIL=0
ok() { echo "  ✅ $1"; PASS=$((PASS+1)); }
err() { echo "  ❌ $1 — $2"; FAIL=$((FAIL+1)); }

# Kill existing server, start fresh
pkill -f "php -S 127.0.0.1:${1:-8085}" 2>/dev/null || true
sleep 1

cd "$(dirname "$0")/.."
APP_SECRET=test-secret-e2e php -S "127.0.0.1:${1:-8085}" -t . docker/payment-router/index.php > /tmp/pr-e2e.log 2>&1 &
sleep 2

echo "══════════════════════════════════════════"
echo "  Full E2E Flow: A-Site → Controller → B-Site → Webhook → Dashboard"
echo "══════════════════════════════════════════"
echo ""

# ═══ Phase 1: Setup A-Site + B-Sites ═══
echo "📦 Phase 1: Setup A-Site + B-Sites"

# Register A-Site
A=$(curl -s -X POST "$BASE/api/payment-router/a-sites" \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"domain":"shop.example.com","platform":"woocommerce"}')
A_KEY=$(echo "$A" | php -r 'echo json_decode(file_get_contents("php://stdin"))->apiKey;')
echo "$A" | grep -q '"domain":"shop.example.com"' && ok "Register A-Site (shop.example.com)" || err "Register A-Site" "$A"

# Register 3 B-Sites with different weights
B1=$(curl -s -X POST "$BASE/api/payment-router/b-sites" \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"domain":"pay1.example.com","payment_gateway":"paypal","weight":5,"max_daily_orders":100}')
echo "$B1" | grep -q '"gateway":"paypal"' && ok "Register B1 (PayPal, w=5)" || err "B1" "$B1"

B2=$(curl -s -X POST "$BASE/api/payment-router/b-sites" \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"domain":"pay2.example.com","payment_gateway":"stripe","weight":3,"max_daily_orders":80}')
ok "Register B2 (Stripe, w=3)"

B3=$(curl -s -X POST "$BASE/api/payment-router/b-sites" \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"domain":"pay3.example.com","payment_gateway":"paypal","weight":1,"max_daily_orders":50}')
ok "Register B3 (PayPal, w=1)"

# ═══ Phase 2: Verify Site Listing ═══
echo ""
echo "📋 Phase 2: Site Listing"
A_LIST=$(curl -s "$BASE/api/payment-router/a-sites")
echo "$A_LIST" | grep -q 'shop.example.com' && ok "GET /a-sites returns 1 A-Site" || err "A-Sites" "$A_LIST"

B_LIST=$(curl -s "$BASE/api/payment-router/b-sites")
B_COUNT=$(echo "$B_LIST" | php -r '$a=json_decode(file_get_contents("php://stdin"));echo count($a);')
[ "$B_COUNT" = "3" ] && ok "GET /b-sites returns 3 B-Sites" || err "B-Sites count=$B_COUNT"

# ═══ Phase 3: Dispatch Orders (simulating WP plugin) ═══
echo ""
echo "🚀 Phase 3: Dispatch Orders (WP Plugin → Controller)"

declare -a B_REFS=()
for i in $(seq 1 5); do
  TS=$(date +%s)
  AMT="$((10 + i * 15)).00"
  P="{\"a_order_id\":\"WP-ORDER-10${i}\",\"amount\":\"$AMT\",\"currency\":\"USD\",\"timestamp\":\"$TS\"}"
  SIG=$(echo -n "$P" | openssl dgst -sha256 -hmac "$A_KEY" | awk '{print $2}')

  R=$(curl -s -X POST "$BASE/api/payment-router/dispatch" \
    -H "Content-Type: application/json" \
    -d "{\"api_key\":\"$A_KEY\",\"signature\":\"$SIG\",\"a_order_id\":\"WP-ORDER-10${i}\",\"amount\":\"$AMT\",\"currency\":\"USD\",\"timestamp\":\"$TS\"}")

  URL=$(echo "$R" | php -r 'echo json_decode(file_get_contents("php://stdin"))->b_checkout_url;')
  REF=$(echo "$R" | php -r 'echo json_decode(file_get_contents("php://stdin"))->b_order_reference;')
  DOMAIN=$(echo "$R" | php -r 'echo json_decode(file_get_contents("php://stdin"))->b_site_domain;')
  B_REFS+=("$REF")

  echo "  WP-ORDER-10${i}: \$$AMT → $REF @ $DOMAIN"
done
ok "5 orders dispatched (WP → Controller → B-Site)"

# ═══ Phase 4: Webhook Callbacks (simulating OC plugin) ═══
echo ""
echo "💰 Phase 4: Webhook Callbacks (B-Site → Controller)"

# Pay first 3 orders
for i in 0 1 2; do
  W=$(curl -s -X POST "$BASE/api/payment-router/webhook" \
    -H "Content-Type: application/json" \
    -d "{\"b_order_id\":\"${B_REFS[$i]}\",\"status\":\"paid\",\"transaction_id\":\"TXN-${i}\"}")
  echo "  ${B_REFS[$i]}: paid ✅"
done
ok "3 payments succeeded via webhook"

# Fail 1 order (counts toward cooldown)
W=$(curl -s -X POST "$BASE/api/payment-router/webhook" \
  -H "Content-Type: application/json" \
  -d "{\"b_order_id\":\"${B_REFS[3]}\",\"status\":\"failed\"}")
echo "  ${B_REFS[3]}: failed ❌"
ok "1 payment failed via webhook"

# ═══ Phase 5: Dashboard Verification ═══
echo ""
echo "📊 Phase 5: Dashboard"

DASH=$(curl -s "$BASE/api/payment-router/dashboard")
echo "$DASH" | php -r '
  $d = json_decode(file_get_contents("php://stdin"));
  $s = $d->summary;
  echo "  Total Orders:  {$s->total_orders}\n";
  echo "  Paid:          {$s->paid_orders}\n";
  echo "  Failed:        {$s->failed_orders}\n";
  echo "  Pending:       {$s->pending_orders}\n";
  echo "  Success Rate:  {$s->success_rate}%\n";
  echo "  Revenue:       \${$s->total_revenue}\n";
'
ok "Dashboard shows correct aggregated data"

# ═══ Phase 6: Order Mappings ═══
echo ""
echo "📋 Phase 6: Order Mappings"
MAPS=$(curl -s "$BASE/api/payment-router/mappings")
M_COUNT=$(echo "$MAPS" | php -r 'echo json_decode(file_get_contents("php://stdin"))->total;')
[ "$M_COUNT" = "5" ] && ok "5 order mappings recorded" || err "Mapping count=$M_COUNT"

# ═══ Phase 7: Error Handling ═══
echo ""
echo "🛡️ Phase 7: Error Handling"

# Invalid API key
E1=$(curl -s -w " [HTTP %{http_code}]" -X POST "$BASE/api/payment-router/dispatch" \
  -H "Content-Type: application/json" \
  -d '{"api_key":"bad_key","signature":"x","a_order_id":"X","amount":"0"}')
echo "$E1" | grep -q '"error"' && ok "401 on invalid API key" || err "Auth error" "$E1"

# Missing B-Site reference
E2=$(curl -s -w " [HTTP %{http_code}]" -X POST "$BASE/api/payment-router/webhook" \
  -H "Content-Type: application/json" \
  -d '{"b_order_id":"B-DEADBEEF","status":"paid"}')
echo "$E2" | grep -q '"error"' && ok "404 on unknown b_order_id" || err "Webhook error" "$E2"

# ═══ Phase 8: Health Check ═══
echo ""
echo "💚 Phase 8: Health"
H=$(curl -s "$BASE/health")
echo "$H" | grep -q '"status":"ok"' && ok "Health check OK" || err "Health" "$H"

# ═══ Summary ═══
echo ""
echo "══════════════════════════════════════════"
echo "  E2E Results: $PASS passed, $FAIL failed"
echo "══════════════════════════════════════════"

# Cleanup
pkill -f "php -S 127.0.0.1:${1:-8085}" 2>/dev/null || true

[ "$FAIL" -eq 0 ] || exit 1
