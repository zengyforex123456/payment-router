#!/bin/bash
# ═══ 健康检查监控 v4 — 进程 + HTTP + Webhook 告警 ═══
# cron: */2 * * * * /bin/bash /root/health-monitor.sh
# 环境变量:
#   ALERT_WEBHOOK_URL   — 告警 Webhook URL (钉钉/飞书/Slack/自定义)
#   ALERT_EMAIL         — 告警邮件 (需要本机 sendmail)
#   HEALTHCHECK_IO_UUID — healthchecks.io ping UUID

LOG=/root/health-monitor.log
WEBHOOK="${ALERT_WEBHOOK_URL:-}"

# ─── Webhook 告警 ───
alert() {
    local app="$1" status="$2" detail="$3"
    local msg="[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $app: $status — $detail"
    echo "$msg" >> "$LOG"

    # healthchecks.io ping (若配置)
    if [ -n "${HEALTHCHECK_IO_UUID:-}" ] && [ "$status" = "DOWN" ]; then
        curl -fsS -m 10 "https://hc-ping.com/$HEALTHCHECK_IO_UUID/fail" > /dev/null 2>&1 || true
    fi

    # Webhook (钉钉/飞书/Slack 兼容)
    if [ -n "$WEBHOOK" ]; then
        curl -fsS -m 10 -X POST "$WEBHOOK" \
            -H 'Content-Type: application/json' \
            -d "{\"msgtype\":\"text\",\"text\":{\"content\":\"🔴 $msg\"}}" > /dev/null 2>&1 || true
    fi

    # Email (fallback)
    if [ -n "${ALERT_EMAIL:-}" ] && command -v sendmail >/dev/null 2>&1; then
        echo "Subject: [$status] $app\n\n$msg" | sendmail "$ALERT_EMAIL" 2>/dev/null || true
    fi
}

recover() {
    local app="$1" detail="$2"
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $app: RECOVERED — $detail" >> "$LOG"
    if [ -n "${HEALTHCHECK_IO_UUID:-}" ]; then
        curl -fsS -m 10 "https://hc-ping.com/$HEALTHCHECK_IO_UUID" > /dev/null 2>&1 || true
    fi
}

# ─── 检查函数 ───
check() {
    local app="$1" domain="${2:-}"

    # 1. 容器存活
    local container="${app}.web.1"
    if ! docker ps --format '{{.Names}}' | grep -q "^${container}$"; then
        alert "$app" "DOWN" "container not running"
        dokku ps:restart "$app" 2>/dev/null
        sleep 5
        if docker ps --format '{{.Names}}' | grep -q "^${container}$"; then
            recover "$app" "restarted"
        else
            alert "$app" "STILL_DOWN" "restart failed"
        fi
        return
    fi

    # 2. HTTP 健康检查
    if [ -n "$domain" ]; then
        local code=$(curl -sk -o /dev/null -w '%{http_code}' "https://$domain/health" --max-time 10 2>/dev/null || echo "000")
        if [ "$code" != "200" ]; then
            alert "$app" "UNHEALTHY" "HTTP $code"
        fi
    fi
}

check "payment-router" "paymentrouter.vip"
check "converge" "converge.sale"
check "adscope" "adscope.vip"
