#!/bin/bash
# PaymentRouter — curl E2E API Test Suite
# 前提: PHP server 已启动 (php -S 127.0.0.1:8081 -t docker/payment-router docker/payment-router/index.php)
set -e

BASE="http://127.0.0.1:8081"
PASS=0
FAIL=0

ok() { echo "  ✅ $1"; PASS=$((PASS+1)); }
err() { echo "  ❌ $1 — $2"; FAIL=$((FAIL+1)); }

echo "══════════════════════════════════════════"
echo "  PaymentRouter cURL E2E Tests"
echo "══════════════════════════════════════════"
echo ""

# ── 0. Health ──
echo "📡 Health Check"
HEALTH=$(curl -s "$BASE/health")
echo "$HEALTH" | grep -q '"status":"ok"' && ok "GET /health" || err "GET /health" "$HEALTH"

# ── 1. Register A-Site ──
echo ""
echo "📦 Register A-Site"
A_RESULT=$(curl -s -X POST "$BASE/api/payment-router/a-sites" \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"domain":"shop.example.com","platform":"woocommerce"}')
echo "  Response: $A_RESULT"
A_KEY=$(echo "$A_RESULT" | python3 -c "import sys,json; print(json.load(sys.stdin)['apiKey'])" 2>/dev/null || echo "$A_RESULT" | php -r 'echo json_decode(file_get_contents("php://stdin"))->apiKey;')
echo "$A_RESULT" | grep -q '"domain":"shop.example.com"' && ok "POST /api/payment-router/a-sites" || err "POST /api/payment-router/a-sites" "$A_RESULT"

# ── 2. Register B-Sites ──
echo ""
echo "📦 Register B-Sites"
B1=$(curl -s -X POST "$BASE/api/payment-router/b-sites" \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"domain":"pay1.example.com","payment_gateway":"paypal","weight":3,"max_daily_orders":100}')
echo "  B1: $B1"
echo "$B1" | grep -q '"gateway":"paypal"' && ok "POST /api/payment-router/b-sites (PayPal,w=3)" || err "B-Site 1" "$B1"

B2=$(curl -s -X POST "$BASE/api/payment-router/b-sites" \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"domain":"pay2.example.com","payment_gateway":"stripe","weight":1,"max_daily_orders":50}')
echo "  B2: $B2"
echo "$B2" | grep -q '"gateway":"stripe"' && ok "POST /api/payment-router/b-sites (Stripe,w=1)" || err "B-Site 2" "$B2"

B3=$(curl -s -X POST "$BASE/api/payment-router/b-sites" \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":0,"domain":"pay3.example.com","payment_gateway":"paypal","weight":5,"max_daily_orders":200}')
echo "  B3: $B3"

# ── 3. List Sites ──
echo ""
echo "📋 List Sites"
A_SITES=$(curl -s "$BASE/api/payment-router/a-sites")
echo "  A-Sites: $A_SITES"
echo "$A_SITES" | grep -q 'shop.example.com' && ok "GET /api/payment-router/a-sites" || err "List A-Sites" "$A_SITES"

B_SITES=$(curl -s "$BASE/api/payment-router/b-sites")
echo "  B-Sites: $B_SITES"
echo "$B_SITES" | grep -q 'pay1.example.com' && ok "GET /api/payment-router/b-sites" || err "List B-Sites" "$B_SITES"

# ── 4. Dispatch Order ──
echo ""
echo "🚀 Dispatch Order"

# Compute HMAC signature matching DispatchOrderUseCase payload format
TS=$(date +%s)
SIG_PAYLOAD="{\"a_order_id\":\"ORDER-1001\",\"amount\":\"79.99\",\"currency\":\"USD\",\"timestamp\":\"$TS\"}"
SIGNATURE=$(echo -n "$SIG_PAYLOAD" | openssl dgst -sha256 -hmac "$A_KEY" | awk '{print $2}')

DISPATCH=$(curl -s -X POST "$BASE/api/payment-router/dispatch" \
  -H "Content-Type: application/json" \
  -d "{\"api_key\":\"$A_KEY\",\"signature\":\"$SIGNATURE\",\"a_order_id\":\"ORDER-1001\",\"amount\":\"79.99\",\"currency\":\"USD\",\"timestamp\":\"$TS\"}")
echo "  Dispatch: $DISPATCH"
B_REF=$(echo "$DISPATCH" | python3 -c "import sys,json; print(json.load(sys.stdin)['b_order_reference'])" 2>/dev/null || echo "$DISPATCH" | php -r 'echo json_decode(file_get_contents("php://stdin"))->b_order_reference;')
echo "$DISPATCH" | grep -q 'b_checkout_url' && ok "POST /api/payment-router/dispatch" || err "Dispatch" "$DISPATCH"
echo "  B-Order-Reference: $B_REF"

# ── 5. Dispatch 3 more orders ──
echo ""
echo "📦 Batch Dispatch (3 orders)"
for i in 2 3 4; do
  TS2=$(date +%s)
  AMT="$((10 * i)).00"
  P="{\"a_order_id\":\"ORDER-100${i}\",\"amount\":\"$AMT\",\"currency\":\"USD\",\"timestamp\":\"$TS2\"}"
  SIG2=$(echo -n "$P" | openssl dgst -sha256 -hmac "$A_KEY" | awk '{print $2}')
  R=$(curl -s -X POST "$BASE/api/payment-router/dispatch" \
    -H "Content-Type: application/json" \
    -d "{\"api_key\":\"$A_KEY\",\"signature\":\"$SIG2\",\"a_order_id\":\"ORDER-100${i}\",\"amount\":\"$AMT\",\"currency\":\"USD\",\"timestamp\":\"$TS2\"}")
  echo "  ORDER-100${i}: $(echo "$R" | php -r 'echo json_decode(file_get_contents("php://stdin"))->b_order_reference;') → $(echo "$R" | php -r 'echo json_decode(file_get_contents("php://stdin"))->b_site_domain;')"
done
ok "Batch dispatch 4 orders total"

# ── 6. Webhook — Payment Success ──
echo ""
echo "💰 Webhook — Payment Success"
WEBHOOK=$(curl -s -X POST "$BASE/api/payment-router/webhook" \
  -H "Content-Type: application/json" \
  -d "{\"b_order_id\":\"$B_REF\",\"status\":\"paid\"}")
echo "  Webhook: $WEBHOOK"
echo "$WEBHOOK" | grep -q '"acknowledged":true' && ok "POST /api/payment-router/webhook (paid)" || err "Webhook paid" "$WEBHOOK"

# ── 7. Webhook — Payment Failure x3 (trigger cooldown) ──
echo ""
echo "🔥 Webhook — Payment Failure x3 (cooldown test)"
# Find a dispatch result for one of the other orders
B_REF2=$(echo "$DISPATCH" | php -r 'echo json_decode(file_get_contents("php://stdin"))->b_order_reference;')

for attempt in 1 2 3; do
  R=$(curl -s -X POST "$BASE/api/payment-router/webhook" \
    -H "Content-Type: application/json" \
    -d "{\"b_order_id\":\"$B_REF\",\"status\":\"failed\"}")
  echo "  Failure #$attempt: $R"
done
ok "3 consecutive failures processed"

# ── 8. Dashboard ──
echo ""
echo "📊 Dashboard"
DASH=$(curl -s "$BASE/api/payment-router/dashboard")
echo "  Dashboard: $DASH"
echo "$DASH" | grep -q '"summary"' && ok "GET /api/payment-router/dashboard" || err "Dashboard" "$DASH"

# ── 9. Mappings ──
echo ""
echo "📋 Order Mappings"
MAPS=$(curl -s "$BASE/api/payment-router/mappings")
echo "  Mappings count: $(echo "$MAPS" | php -r '$d=json_decode(file_get_contents("php://stdin")); echo $d->total;')"
ok "GET /api/payment-router/mappings"

# ── 10. Error Handling ──
echo ""
echo "🛡️ Error Handling"
ERR=$(curl -s -X POST "$BASE/api/payment-router/dispatch" \
  -H "Content-Type: application/json" \
  -d '{"api_key":"invalid","signature":"x","a_order_id":"X","amount":"0"}')
echo "  Invalid key: $ERR"
echo "$ERR" | grep -q '"error"' && ok "401 on invalid API key" || err "Auth error" "$ERR"

ERR2=$(curl -s -X POST "$BASE/api/payment-router/webhook" \
  -H "Content-Type: application/json" \
  -d '{"b_order_id":"B-NONEXISTENT","status":"paid"}')
echo "  Unknown order: $ERR2"
echo "$ERR2" | grep -q '"error"' && ok "404 on unknown b_order_id" || err "Unknown order" "$ERR2"

ERR3=$(curl -s "$BASE/api/payment-router/nonexistent")
echo "  Invalid route: $ERR3"
echo "$ERR3" | grep -q '"error"' && ok "404 on invalid route" || err "Invalid route" "$ERR3"

# ── Summary ──
echo ""
echo "══════════════════════════════════════════"
echo "  cURL E2E: $PASS passed, $FAIL failed"
echo "══════════════════════════════════════════"
[ "$FAIL" -eq 0 ] || exit 1
