#!/bin/bash
# ═══ Dokku DevOps Dashboard — 统一状态仪表盘 ═══
# 层: L4 横切层 (可观察)
# 单一职责: 一屏展示 Apps/DBs/Backups/SSL/Resources 全貌
# 用法: bash dashboard.sh [--json]
set -euo pipefail

HOST="${HOST:-137.184.225.93}"
SSH="ssh root@${HOST}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJ_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
REPORTS="$PROJ_ROOT/reports"
win_path() { echo "$1" | sed 's|^/\([a-z]\)/|\U\1:/|'; }

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
BOLD='\033[1m'; DIM='\033[2m'

JSON_OUT=false; [ "${1:-}" = "--json" ] && JSON_OUT=true

# ─── 数据采集 ───

get_apps() {
    $SSH "docker ps --format '{{.Names}}\t{{.Status}}' --filter 'label=dokku'" 2>/dev/null | while IFS=$'\t' read -r name status; do
        local app=$(echo "$name" | sed 's/\..*//')
        local domain=$($SSH "dokku domains:report $app --dokku-domains-simple 2>/dev/null" | head -1 || echo "")
        local http="N/A"
        [ -n "$domain" ] && http=$(curl -sk -o /dev/null -w '%{http_code}' "https://$domain/" --max-time 5 2>/dev/null || echo "ERR")
        echo "$app|$status|$domain|$http"
    done
}

get_dbs() {
    $SSH "dokku mysql:list 2>/dev/null" | tail -n +2 | while read -r db; do
        [ -z "$db" ] && continue
        local linked=$($SSH "dokku mysql:info $db --links 2>/dev/null" | head -1 || echo "-")
        local size=$($SSH "docker exec dokku.mysql.$db mysql -u mysql -p\$(docker exec dokku.mysql.$db printenv MYSQL_PASSWORD 2>/dev/null) -e 'SELECT ROUND(SUM(data_length+index_length)/1024/1024,1) FROM information_schema.tables' 2>/dev/null" | tail -1 | tr -d ' ' || echo "?")
        echo "$db|${size}MB|$linked"
    done
}

get_backups() {
    $SSH "ls -lt /root/backups/*.sql.gz 2>/dev/null" | head -10 | awk '{print $NF, $5}' | while read -r fname size; do
        local dbname=$(basename "$fname" | sed 's/backup-//;s/-[0-9].*//')
        local ts=$(basename "$fname" | grep -oP '\d{8}_\d{4}')
        echo "$dbname|$size|$ts|$fname"
    done
}

get_ssl() {
    for domain in converge.sale paymentrouter.vip adscope.vip; do
        local expiry="N/A"
        local days=-1
        local cert_info=$(echo | openssl s_client -servername "$domain" -connect "$domain:443" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null)
        if [ -n "$cert_info" ]; then
            expiry=$(echo "$cert_info" | cut -d= -f2)
            local expiry_epoch=$(date -d "$expiry" +%s 2>/dev/null || echo 0)
            local now_epoch=$(date +%s)
            days=$(( (expiry_epoch - now_epoch) / 86400 ))
        fi
        echo "$domain|$days|$expiry"
    done
}

get_resources() {
    local cpu=$($SSH "top -bn1 | grep 'Cpu(s)' | awk '{print 100-\$8}'" 2>/dev/null || echo "?")
    local mem=$($SSH "free -m | grep Mem | awk '{print \$3,\$2}'" 2>/dev/null || echo "? ?")
    local disk=$($SSH "df -h / | tail -1 | awk '{print \$5,\$2}'" 2>/dev/null || echo "? ?")
    echo "$cpu|$mem|$disk"
}

# ─── 渲染 ───

render_text() {
    echo ""
    echo -e "${BOLD}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BOLD}║     Dokku DevOps Dashboard — ${HOST}     ║${NC}"
    echo -e "${BOLD}╠══════════════════════════════════════════════════════════════╣${NC}"

    # Apps
    echo -e "${BOLD}║ 🔭 Apps${NC}"
    while IFS='|' read -r app status domain http; do
        local sym="✅"; [ "$http" = "ERR" ] || [ "$http" = "000" ] && sym="❌"; [ "$http" = "N/A" ] && sym="⬜"
        local up=$(echo "$status" | grep -oP 'Up \S+' || echo "Down")
        printf "║   %s %-16s %-10s | %-24s %s\n" "$sym" "$app" "$up" "$domain" "$http"
    done <<< "$(get_apps)"

    # Databases
    echo -e "${BOLD}╠══════════════════════════════════════════════════════════════╣${NC}"
    echo -e "${BOLD}║ 🗄️  Databases${NC}"
    while IFS='|' read -r db size linked; do
        printf "║   ✅ %-18s %6s | Linked: %s\n" "$db" "$size" "$linked"
    done <<< "$(get_dbs)"

    # Backups
    echo -e "${BOLD}╠══════════════════════════════════════════════════════════════╣${NC}"
    echo -e "${BOLD}║ 📦 Backups${NC}"
    while IFS='|' read -r dbname size ts fname; do
        local age="?"; [ -n "$ts" ] && age="$ts"
        printf "║   ✅ %-18s %6s | %s\n" "$dbname" "$size" "$age"
    done <<< "$(get_backups)"

    # SSL
    echo -e "${BOLD}╠══════════════════════════════════════════════════════════════╣${NC}"
    echo -e "${BOLD}║ 🔒 SSL${NC}"
    while IFS='|' read -r domain days expiry; do
        local sym="✅"; [ "$days" -lt 30 ] 2>/dev/null && sym="⚠️"; [ "$days" -lt 0 ] 2>/dev/null && sym="❌"
        local label="$days days left"; [ "$days" -lt 0 ] 2>/dev/null && label="Not configured"
        printf "║   %s %-24s %s\n" "$sym" "$domain" "$label"
    done <<< "$(get_ssl)"

    # Resources
    echo -e "${BOLD}╠══════════════════════════════════════════════════════════════╣${NC}"
    echo -e "${BOLD}║ 📊 Resources${NC}"
    IFS='|' read -r cpu mem disk <<< "$(get_resources)"
    local mem_used=$(echo "$mem" | awk '{print $1}')
    local mem_total=$(echo "$mem" | awk '{print $2}')
    printf "║   CPU: %s%%  |  RAM: %s/%sMB  |  Disk: %s\n" "$cpu" "$mem_used" "$mem_total" "$disk"

    echo -e "${BOLD}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

render_json() {
    local apps_json="["; local first=true
    while IFS='|' read -r app status domain http; do
        [ "$first" = true ] && first=false || apps_json+=","
        apps_json+="{\"app\":\"$app\",\"status\":\"$status\",\"domain\":\"$domain\",\"http\":\"$http\"}"
    done <<< "$(get_apps)"
    apps_json+="]"

    local dbs_json="["; first=true
    while IFS='|' read -r db size linked; do
        [ "$first" = true ] && first=false || dbs_json+=","
        dbs_json+="{\"db\":\"$db\",\"size\":\"$size\",\"linked\":\"$linked\"}"
    done <<< "$(get_dbs)"
    dbs_json+="]"

    local backups_json="["; first=true
    while IFS='|' read -r dbname size ts fname; do
        [ "$first" = true ] && first=false || backups_json+=","
        backups_json+="{\"db\":\"$dbname\",\"size\":\"$size\",\"timestamp\":\"$ts\"}"
    done <<< "$(get_backups)"
    backups_json+="]"

    local ssl_json="["; first=true
    while IFS='|' read -r domain days expiry; do
        [ "$first" = true ] && first=false || ssl_json+=","
        ssl_json+="{\"domain\":\"$domain\",\"days_left\":$days,\"expiry\":\"$expiry\"}"
    done <<< "$(get_ssl)"
    ssl_json+="]"

    IFS='|' read -r cpu mem disk <<< "$(get_resources)"

    python -c "
import json
print(json.dumps({
    'host': '$HOST',
    'timestamp': '$(date -u +%Y-%m-%dT%H:%M:%SZ)',
    'apps': json.loads('''$apps_json'''),
    'databases': json.loads('''$dbs_json'''),
    'backups': json.loads('''$backups_json'''),
    'ssl': json.loads('''$ssl_json'''),
    'resources': {'cpu_pct': '$cpu', 'memory_used_mb': '$(echo "$mem" | awk '{print $1}')', 'memory_total_mb': '$(echo "$mem" | awk '{print $2}')', 'disk': '$disk'}
}, indent=2, ensure_ascii=False, default=str))
" 2>/dev/null
}

# ─── Main ───
if $JSON_OUT; then
    render_json
else
    render_text
fi

# Save snapshot
mkdir -p "$REPORTS"
python -c "
import json, subprocess, os
result = subprocess.run(['bash', '$0', '--json'], capture_output=True, text=True)
if result.returncode == 0:
    data = json.loads(result.stdout)
    data['saved_at'] = '$(date -u +%Y-%m-%dT%H:%M:%SZ)'
    with open('$REPORTS/devops-dashboard-snapshot.json', 'w') as f:
        json.dump(data, f, indent=2, default=str)
" 2>/dev/null || true
