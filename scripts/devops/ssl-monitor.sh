#!/bin/bash
# ═══ SSL Certificate Monitor — 证书过期监控 ═══
# 层: L4 横切层 (可验证)
# 单一职责: 检查所有域名 SSL 证书过期时间并告警
# 用法: bash ssl-monitor.sh [--check-only] [--json]
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

CHECK_ONLY=false; JSON_OUT=false
while [ $# -gt 0 ]; do case "$1" in
    --check-only) CHECK_ONLY=true ;;
    --json) JSON_OUT=true ;;
esac; shift; done

# ─── 域名列表 ───
DOMAINS=("converge.sale" "www.converge.sale" "paymentrouter.vip" "www.paymentrouter.vip" "adscope.vip")

check_domain() {
    local domain="$1"
    local result=""
    local days=-1

    result=$(echo | openssl s_client -servername "$domain" -connect "$domain:443" 2>/dev/null | openssl x509 -noout -dates 2>/dev/null)
    if [ -z "$result" ]; then
        echo "$domain|N/A|N/A|no_ssl"
        return
    fi

    local enddate=$(echo "$result" | grep "notAfter" | cut -d= -f2)
    local end_epoch=$(date -d "$enddate" +%s 2>/dev/null || echo 0)
    local now_epoch=$(date +%s)
    days=$(( (end_epoch - now_epoch) / 86400 ))

    local status="ok"
    [ "$days" -le 30 ] && status="warn"
    [ "$days" -le 7 ] && status="critical"
    [ "$days" -le 0 ] && status="expired"

    echo "$domain|$days|$enddate|$status"
}

S_EXPIRED=0; S_CRITICAL=0; S_WARN=0; S_OK=0

for domain in "${DOMAINS[@]}"; do
    IFS='|' read -r dom days expiry status <<< "$(check_domain "$domain")"

    case "$status" in
        ok)       sym="${GREEN}✅${NC}"; S_OK=$((S_OK+1)) ;;
        warn)     sym="${YELLOW}⚠️ ${NC}"; S_WARN=$((S_WARN+1)) ;;
        critical) sym="${RED}🔴${NC}"; S_CRITICAL=$((S_CRITICAL+1)) ;;
        expired)  sym="${RED}💀${NC}"; S_EXPIRED=$((S_EXPIRED+1)) ;;
        no_ssl)   sym="⬜"; ;;
    esac

    if $JSON_OUT; then
        echo "{\"domain\":\"$dom\",\"days_left\":$days,\"expiry\":\"$expiry\",\"status\":\"$status\"}"
    else
        printf "  %s %-28s %s\n" "$sym" "$dom" "${days}d left ($expiry)"
    fi
done

if ! $JSON_OUT && ! $CHECK_ONLY; then
    echo ""
    echo "──────────────────────────────────────"
    echo -e "  ${GREEN}OK: $S_OK${NC}  ${YELLOW}Warn: $S_WARN${NC}  ${RED}Critical: $S_CRITICAL${NC}  💀Expired: $S_EXPIRED"
    echo "──────────────────────────────────────"

    if [ "$S_CRITICAL" -gt 0 ] || [ "$S_EXPIRED" -gt 0 ]; then
        echo -e "${RED}⚠️  有证书即将过期或已过期！运行: dokku letsencrypt:auto-renew${NC}"
    fi
fi

# 写入报告
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPORTS="$(cd "$SCRIPT_DIR/../.." && pwd)/reports"
mkdir -p "$REPORTS"
python -c "
import json, subprocess
result = subprocess.run(['bash', '$0', '--json'], capture_output=True, text=True)
lines = [l for l in result.stdout.strip().split('\n') if l]
data = [json.loads(l) for l in lines]
with open('$REPORTS/devops-ssl-report.json', 'w') as f:
    json.dump({'checked_at': '$(date -u +%Y-%m-%dT%H:%M:%SZ)', 'certificates': data}, f, indent=2)
" 2>/dev/null || true

[ "$S_CRITICAL" -eq 0 ] && [ "$S_EXPIRED" -eq 0 ] && exit 0 || exit 1
