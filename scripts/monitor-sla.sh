#!/bin/bash
# monitor-sla.sh — Converge SLA 监控告警
# 用法:
#   bash scripts/monitor-sla.sh             单次健康检查 (适合 cron)
#   bash scripts/monitor-sla.sh --loop 60   每 60s 循环检查
#   bash scripts/monitor-sla.sh --json      JSON 输出 (适合 CI)
#
# 退出码: 0=全绿  1=WARNING(降级)  2=CRITICAL(必须处理)
# Cron:  */5 * * * * bash /var/www/converge/scripts/monitor-sla.sh >> /var/log/converge/sla.log
set -euo pipefail
cd "$(dirname "$0")/.."

BASE_URL="${SLA_URL:-http://localhost:80}"
TIMEOUT="${SLA_TIMEOUT:-10}"
LOG_DIR="${SLA_LOG_DIR:-storage/logs}"
NOW=$(date -Iseconds 2>/dev/null || date '+%Y-%m-%dT%H:%M:%S%z')

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

warnings=0
criticals=0
checks_passed=0
checks_total=0
results=()

check() {
    local name="$1" cmd="$2" warn_thresh="$3" crit_thresh="$4"
    checks_total=$((checks_total + 1))

    if eval "$cmd" 2>/dev/null; then
        results+=("✅ $name")
        checks_passed=$((checks_passed + 1))
    elif [ "$warn_thresh" != "" ] && eval "$warn_thresh" 2>/dev/null; then
        results+=("⚠️  $name")
        warnings=$((warnings + 1))
    else
        results+=("❌ $name")
        criticals=$((criticals + 1))
    fi
}

# ═══════════════════════════════════════
# 1. HTTP Health
# ═══════════════════════════════════════
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout "$TIMEOUT" "$BASE_URL/health" 2>/dev/null || echo "000")
HTTP_TIME=$(curl -s -o /dev/null -w "%{time_total}" --connect-timeout "$TIMEOUT" "$BASE_URL/health" 2>/dev/null || echo "999")

if [ "$HTTP_CODE" = "200" ]; then
    results+=("✅ Health: ${HTTP_CODE} (${HTTP_TIME}s)")
    checks_passed=$((checks_passed + 1))
elif [ "$HTTP_CODE" = "000" ]; then
    criticals=$((criticals + 1))
    results+=("❌ Health: UNREACHABLE")
else
    criticals=$((criticals + 1))
    results+=("❌ Health: ${HTTP_CODE}")
fi
checks_total=$((checks_total + 1))

# ═══════════════════════════════════════
# 2. Landing Page
# ═══════════════════════════════════════
LP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout "$TIMEOUT" "$BASE_URL/landing.php" 2>/dev/null || echo "000")
LP_SIZE=$(curl -s --connect-timeout "$TIMEOUT" "$BASE_URL/landing.php" 2>/dev/null | wc -c || echo "0")

if [ "$LP_CODE" = "200" ] && [ "$LP_SIZE" -gt 500 ]; then
    results+=("✅ Landing: ${LP_CODE} (${LP_SIZE}B)")
    checks_passed=$((checks_passed + 1))
elif [ "$LP_CODE" = "200" ] && [ "$LP_SIZE" -le 500 ]; then
    results+=("⚠️  Landing: ${LP_CODE} but only ${LP_SIZE}B (possible blank page)")
    warnings=$((warnings + 1))
else
    results+=("❌ Landing: ${LP_CODE} (${LP_SIZE}B)")
    criticals=$((criticals + 1))
fi
checks_total=$((checks_total + 1))

# ═══════════════════════════════════════
# 3. DB Connectivity (via health API)
# ═══════════════════════════════════════
DB_OK=$(curl -s --connect-timeout "$TIMEOUT" "$BASE_URL/health" 2>/dev/null | grep -c '"db"' || echo "0")
if [ "$DB_OK" -gt 0 ]; then
    results+=("✅ DB: reachable (health check)")
    checks_passed=$((checks_passed + 1))
else
    results+=("⚠️  DB: not reported in health check")
    warnings=$((warnings + 1))
fi
checks_total=$((checks_total + 1))

# ═══════════════════════════════════════
# 4. Docker Container Status
# ═══════════════════════════════════════
if command -v docker &>/dev/null; then
    RUNNING=$(docker ps --filter name=converge --format '{{.Names}}' 2>/dev/null | wc -l)
    if [ "$RUNNING" -ge 3 ]; then
        results+=("✅ Docker: ${RUNNING} containers")
        checks_passed=$((checks_passed + 1))
    elif [ "$RUNNING" -gt 0 ]; then
        results+=("⚠️  Docker: only ${RUNNING}/4 containers")
        warnings=$((warnings + 1))
    else
        results+=("❌ Docker: 0 containers running")
        criticals=$((criticals + 1))
    fi
    checks_total=$((checks_total + 1))
fi

# ═══════════════════════════════════════
# 5. PHP Error Rate (last 5 min)
# ═══════════════════════════════════════
if command -v docker &>/dev/null && docker ps --filter name=converge --format '{{.Names}}' | grep -q "." 2>/dev/null; then
    APP_CONTAINER=$(docker ps --filter name=converge --format '{{.Names}}' | head -1)
    ERRORS=$(docker logs --since=5m "$APP_CONTAINER" 2>&1 | grep -ciE "PHP Fatal|PHP Parse|Uncaught|stack trace" || echo "0")
    if [ "$ERRORS" -eq 0 ]; then
        results+=("✅ PHP: 0 errors (5min)")
        checks_passed=$((checks_passed + 1))
    elif [ "$ERRORS" -le 5 ]; then
        results+=("⚠️  PHP: ${ERRORS} errors (5min)")
        warnings=$((warnings + 1))
    else
        results+=("❌ PHP: ${ERRORS} errors (5min)")
        criticals=$((criticals + 1))
    fi
    checks_total=$((checks_total + 1))
fi

# ═══════════════════════════════════════
# 6. Disk Usage
# ═══════════════════════════════════════
DISK_PCT=$(df -h . 2>/dev/null | awk 'NR==2 {gsub(/%/,""); print $5}' || echo "0")
if [ "$DISK_PCT" -lt 70 ]; then
    results+=("✅ Disk: ${DISK_PCT}%")
    checks_passed=$((checks_passed + 1))
elif [ "$DISK_PCT" -lt 85 ]; then
    results+=("⚠️  Disk: ${DISK_PCT}%")
    warnings=$((warnings + 1))
else
    results+=("❌ Disk: ${DISK_PCT}% CRITICAL")
    criticals=$((criticals + 1))
fi
checks_total=$((checks_total + 1))

# ═══════════════════════════════════════
# Score & Report
# ═══════════════════════════════════════
SCORE=$(( checks_passed * 100 / checks_total ))
if [ "$criticals" -gt 0 ]; then
    STATUS="CRITICAL"
    COLOR="$RED"
    EXIT_CODE=2
elif [ "$warnings" -gt 0 ] || [ "$SCORE" -lt 80 ]; then
    STATUS="WARNING"
    COLOR="$YELLOW"
    EXIT_CODE=1
else
    STATUS="HEALTHY"
    COLOR="$GREEN"
    EXIT_CODE=0
fi

MODE="${1:-}"
if [ "$MODE" = "--json" ]; then
    echo "{\"status\":\"$STATUS\",\"score\":$SCORE,\"passed\":$checks_passed,\"total\":$checks_total,\"warnings\":$warnings,\"criticals\":$criticals,\"timestamp\":\"$NOW\"}"
    exit $EXIT_CODE
fi

echo "══════════════════════════════════════════"
echo "  Converge SLA Monitor — $STATUS ($SCORE%)"
echo "  $NOW"
echo "══════════════════════════════════════════"
for r in "${results[@]}"; do echo "  $r"; done
echo "══════════════════════════════════════════"
echo "  Passed: $checks_passed/$checks_total | WARN: $warnings | CRIT: $criticals"
echo "══════════════════════════════════════════"

# ── Loop mode ──
if [ "$MODE" = "--loop" ]; then
    INTERVAL="${2:-60}"
    echo "🔄 Looping every ${INTERVAL}s (Ctrl+C to stop)"
    while true; do
        sleep "$INTERVAL"
        bash "$0"
    done
fi

exit $EXIT_CODE
