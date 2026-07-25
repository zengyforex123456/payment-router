#!/bin/bash
# ═══ Dokku DevOps Logs — 统一日志查看器 ═══
# 层: L4 横切层 (可观察)
# 单一职责: 聚合查看所有 Dokku app 日志
# 用法: bash logs.sh <app> [--tail] [--errors] [--since 1h]
set -euo pipefail

HOST="${HOST:-137.184.225.93}"
SSH="ssh root@${HOST}"
RED='\033[0;31m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'

APP="${1:-}"
TAIL=false; ERRORS=false; SINCE=""

shift 2>/dev/null || true
while [ $# -gt 0 ]; do
    case "$1" in
        --tail) TAIL=true ;;
        --errors) ERRORS=true ;;
        --since) SINCE="$2"; shift ;;
    esac
    shift 2>/dev/null || break
done

if [ -z "$APP" ] || [ "$APP" = "--help" ]; then
    echo "用法: bash logs.sh <app> [options]"
    echo "  <app>         converge | payment-router | adscope"
    echo "  --tail        实时跟踪"
    echo "  --errors      只看 ERROR/WARN/Fatal"
    echo "  --since 1h    最近 1 小时 (1h/30m/2d)"
    exit 0
fi

# ─── 查找容器 ───
CONTAINER=$($SSH "docker ps --format '{{.Names}}' --filter 'name=${APP}'" 2>/dev/null | head -1)
[ -z "$CONTAINER" ] && { echo -e "${RED}❌ App '$APP' 未运行${NC}"; exit 1; }

echo -e "${CYAN}═══ $APP ($CONTAINER) ═══${NC}"

CMD="docker logs $CONTAINER"

if [ -n "$SINCE" ]; then
    CMD="$CMD --since $SINCE"
fi

if $TAIL; then
    CMD="$CMD -f"
fi

if $ERRORS; then
    $SSH "$CMD 2>&1" | grep -iE --color=always 'error|exception|fatal|fail|warn|critical|panic|traceback' || echo "  (无错误日志)"
else
    $SSH "$CMD 2>&1"
fi
