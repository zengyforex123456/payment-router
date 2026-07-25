#!/bin/bash
# ═══ Post-Deploy Smoke Test — 部署后冒烟测试 ═══
# 层: L4 横切层 (可验证)
# 单一职责: 部署后验证关键端点 + DB 连接 + 响应时间
# 用法: bash smoke-test.sh <app> [--all] [--json]
set -euo pipefail

HOST="${HOST:-137.184.225.93}"
SSH="ssh root@${HOST}"
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

APP="${1:-}"; JSON_OUT=false; ALL=false
[ "${1:-}" = "--all" ] && ALL=true
[ "${2:-}" = "--json" ] && JSON_OUT=true

# ─── 项目端点配置 ───
declare -A APP_DOMAINS=( [converge]="converge.sale" [payment-router]="paymentrouter.vip" [adscope]="adscope.vip" )

declare -A APP_PATHS
APP_PATHS[converge]="/ /register.php /login-v2.php"
APP_PATHS[payment-router]="/ /health /login"
APP_PATHS[adscope]="/ /api/health"

PASS=0; FAIL=0; WARN=0

check() {
    local desc="$1" result="$2" detail="$3"
    case "$result" in
        pass) echo -e "  ${GREEN}✅${NC} $desc"; PASS=$((PASS+1)) ;;
        fail) echo -e "  ${RED}❌${NC} $desc — $detail"; FAIL=$((FAIL+1)) ;;
        warn) echo -e "  ${YELLOW}⚠️ ${NC} $desc — $detail"; WARN=$((WARN+1)) ;;
    esac
}

smoke_app() {
    local app="$1"
    local domain="${APP_DOMAINS[$app]:-}"

    echo ""
    echo "═══ Smoke Test: $app ($domain) ═══"

    # 1. 容器进程
    local container=$($SSH "docker ps --format '{{.Names}}' --filter 'name=${app}'" 2>/dev/null | head -1)
    if [ -n "$container" ]; then
        check "Container running" "pass" "$container"
    else
        check "Container running" "fail" "No container found"
        return 1
    fi

    # 2. HTTP 端点
    if [ -n "$domain" ]; then
        for path in ${APP_PATHS[$app]:-/}; do
            local start=$(date +%s%N)
            local code=$(curl -sk -o /dev/null -w '%{http_code}' "http://$domain$path" --max-time 10 2>/dev/null || echo "000")
            local end=$(date +%s%N)
            local ms=$(( (end - start) / 1000000 ))

            if [ "$code" = "200" ] || [ "$code" = "302" ]; then
                local warn=""
                [ "$ms" -gt 5000 ] && warn="(${ms}ms > 5s!)"
                check "HTTP $code $path" "pass" "${ms}ms $warn"
            else
                check "HTTP $code $path" "fail" "expected 200/302, got $code"
            fi
        done
    fi

    # 3. 数据库连接检查
    local db=$($SSH "dokku mysql:list 2>/dev/null" | grep "$app" | head -1 || echo "")
    if [ -n "$db" ]; then
        local alive=$($SSH "docker exec dokku.mysql.$db mysqladmin ping --silent 2>/dev/null" && echo "ok" || echo "fail")
        [ "$alive" = "ok" ] && check "DB: $db" "pass" "alive" || check "DB: $db" "fail" "no response"
    else
        check "DB linked" "warn" "No MySQL found for $app"
    fi

    # 4. Disk check on container
    local disk=$($SSH "docker exec $container df -h / 2>/dev/null | tail -1 | awk '{print \$5}'" || echo "?")
    local disk_pct=$(echo "$disk" | tr -d '%')
    if [ "$disk_pct" -gt 90 ] 2>/dev/null; then
        check "Disk usage" "fail" "${disk} > 90%!"
    else
        check "Disk usage" "pass" "$disk"
    fi
}

if $ALL; then
    for app in "${!APP_DOMAINS[@]}"; do
        smoke_app "$app" || true
    done
elif [ -n "$APP" ]; then
    smoke_app "$APP"
else
    echo "用法: bash smoke-test.sh <app> [--json]"
    echo "      bash smoke-test.sh --all"
    echo "  Apps: converge | payment-router | adscope"
    exit 1
fi

echo ""
echo "──────────────────────────────────────"
echo -e "  ${GREEN}Pass: $PASS${NC}  ${YELLOW}Warn: $WARN${NC}  ${RED}Fail: $FAIL${NC}"
echo "──────────────────────────────────────"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
