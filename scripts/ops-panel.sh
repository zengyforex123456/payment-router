#!/bin/bash
# ops-panel.sh — Converge 运维工具箱 (被 ops-commander Agent 调用)
# 用法:
#   bash scripts/ops-panel.sh status     # 健康仪表盘
#   bash scripts/ops-panel.sh logs       # 错误日志聚合
#   bash scripts/ops-panel.sh diagnose   # 故障诊断
#   bash scripts/ops-panel.sh backup     # 数据库备份
#   bash scripts/ops-panel.sh preflight  # 部署前检查
# 退出码: 0=正常  1=异常  2=严重

set -euo pipefail
cd "$(dirname "$0")/.."

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
ok()   { echo -e "  [${GREEN}PASS${NC}] $1"; }
fail() { echo -e "  [${RED}FAIL${NC}] $1"; }
warn() { echo -e "  [${YELLOW}WARN${NC}] $1"; }
info() { echo -e "  [${CYAN}INFO${NC}] $1"; }

CMD="${1:-status}"
shift || true

# ═══════════════════════════════════════
# status — 健康仪表盘
# ═══════════════════════════════════════
status() {
    echo "══════════════════════════════════════════"
    echo "  Converge 运维面板 — $(date '+%Y-%m-%d %H:%M:%S')"
    echo "══════════════════════════════════════════"

    echo ""
    echo "┌── Git ────────────────────────────────┐"
    BRANCH=$(git branch --show-current)
    COMMIT=$(git log --oneline -1)
    echo "  分支: $BRANCH"
    echo "  最后提交: $COMMIT"

    echo ""
    echo "├── 容器 ───────────────────────────────┤"
    if command -v docker &>/dev/null && docker ps &>/dev/null 2>&1; then
        docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" --filter name=converge 2>/dev/null || echo "  (无 Converge 容器)"
    else
        warn "Docker 不可用"
    fi

    echo ""
    echo "├── 端点 ───────────────────────────────┤"
    for url in "http://localhost/health" "http://localhost:8080/health" "http://localhost/landing.php" "http://localhost:8080/landing.php"; do
        CODE=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 3 "$url" 2>/dev/null || echo "000")
        if [ "$CODE" = "200" ] || [ "$CODE" = "302" ]; then
            ok "$url → $CODE"
        else
            fail "$url → $CODE"
        fi
    done

    echo ""
    echo "├── 端口 ───────────────────────────────┤"
    if command -v netstat &>/dev/null; then
        netstat -ano 2>/dev/null | grep -E "LISTENING.*:(80|8080) " | while read line; do
            echo "  $line"
        done
    elif command -v ss &>/dev/null; then
        ss -tlnp | grep -E ":(80|8080) " || echo "  (80/8080 未监听)"
    fi

    echo ""
    echo "├── PortProxy ───────────────────────────┤"
    if command -v netsh &>/dev/null 2>&1; then
        netsh interface portproxy show all 2>/dev/null | grep -E "80|8080" || echo "  (无 80/8080 转发规则)"
    else
        echo "  (非 Windows, 跳过)"
    fi

    echo ""
    echo "├── 磁盘 ───────────────────────────────┤"
    if command -v docker &>/dev/null; then
        docker system df 2>/dev/null | head -5 || true
    fi

    echo ""
    echo "├── 最近错误 ───────────────────────────┤"
    docker logs --tail=10 converge-app-1 2>&1 | grep -iE "error|exception|fatal|PHP Fatal" || echo "  (无)"
}

# ═══════════════════════════════════════
# logs — 错误日志聚合
# ═══════════════════════════════════════
logs() {
    local LINES="${1:-50}"
    echo "═══ App 日志 (最近 $LINES) ═══"
    docker logs --tail="$LINES" converge-app-1 2>&1 || echo "(无 app 容器)"

    echo ""
    echo "═══ Error/Fatal 过滤 ═══"
    docker logs --tail=200 converge-app-1 2>&1 | grep -iE "PHP Fatal|PHP Warning|PHP Parse|Uncaught|stack trace" | tail -20 || echo "(无 PHP 错误)"

    echo ""
    echo "═══ MySQL Slow ═══"
    docker exec converge-mysql-1 cat /var/log/mysql/slow.log 2>/dev/null | tail -10 || echo "(无慢查询日志)"
}

# ═══════════════════════════════════════
# diagnose — 系统性诊断
# ═══════════════════════════════════════
diagnose() {
    echo "═══ Converge 系统性诊断 ═══"
    FAILS=0

    echo "① 容器状态..."
    RUNNING=$(docker ps --filter name=converge --format '{{.Names}}' | wc -l)
    if [ "$RUNNING" -ge 3 ]; then
        ok "3+ 容器运行中"
    else
        fail "只有 $RUNNING 个 Converge 容器 (预期 3+)"
        FAILS=$((FAILS+1))
    fi

    echo "② Health 端点..."
    HEALTH=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 3 http://localhost/health 2>/dev/null || echo "000")
    if [ "$HEALTH" = "200" ]; then
        ok "Health: 200"
    else
        fail "Health: $HEALTH"
        FAILS=$((FAILS+1))
    fi

    echo "③ Landing Page..."
    LP=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 3 http://localhost/landing.php 2>/dev/null || echo "000")
    if [ "$LP" = "200" ]; then
        ok "Landing: 200"
    else
        fail "Landing: $LP"
        FAILS=$((FAILS+1))
    fi

    echo "④ PHP 语法..."
    docker exec source-app-1 php -l /var/www/converge/public/landing.php 2>/dev/null && ok "landing.php 语法OK" || fail "landing.php 语法错误"

    echo "⑤ PHP 运行时..."
    OUT=$(docker exec source-app-1 php /var/www/converge/public/landing.php 2>&1)
    if echo "$OUT" | grep -q "DOCTYPE"; then
        ok "landing.php 可渲染"
    else
        fail "landing.php 运行异常"
        echo "  输出: $(echo "$OUT" | head -3)"
        FAILS=$((FAILS+1))
    fi

    echo "⑥ 端口转发..."
    if command -v netsh &>/dev/null 2>&1; then
        PP=$(netsh interface portproxy show all 2>/dev/null | grep -c "80\|8080" || echo "0")
        if [ "$PP" -gt 0 ]; then
            warn "检测到 portproxy 规则 (可能劫持流量)"
            netsh interface portproxy show all 2>/dev/null | grep "80\|8080"
        else
            ok "无端口劫持"
        fi
    fi

    echo ""
    if [ "$FAILS" -eq 0 ]; then
        echo "🎉 诊断完成 — 0 故障"
    else
        echo "❌ 发现 $FAILS 个故障 — 需要立即处理"
    fi
}

# ═══════════════════════════════════════
# backup — 数据库备份
# ═══════════════════════════════════════
backup() {
    BACKUP_DIR="${1:-data/backups}"
    mkdir -p "$BACKUP_DIR"
    BACKUP_FILE="$BACKUP_DIR/converge-$(date +%Y%m%d-%H%M%S).sql.gz"

    echo "📦 备份数据库..."
    docker exec converge-mysql-1 mysqldump -u root -p"${DB_PASSWORD:-converge_root_2024}" converge 2>/dev/null | gzip > "$BACKUP_FILE"

    if [ -s "$BACKUP_FILE" ]; then
        echo "✅ 备份完成: $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"
    else
        echo "❌ 备份失败"
        exit 1
    fi
}

# ═══════════════════════════════════════
# preflight — 部署前检查
# ═══════════════════════════════════════
preflight() {
    echo "═══ 部署前检查 ═══"
    FAILS=0

    echo "① Git 干净..."
    if git diff-index --quiet HEAD --; then
        ok "工作区干净"
    else
        warn "有未提交变更："
        git status --short | head -5
    fi

    echo "② PHP 语法 (landing.php)..."
    php -l public/landing.php > /dev/null 2>&1 && ok "语法OK" || fail "语法错误"

    echo "③ Docker 运行..."
    docker ps --filter name=converge --format '{{.Names}}' | grep -q "." && ok "Docker 在线" || fail "Docker 未运行"

    echo "④ 健康检查..."
    curl -sf http://localhost/health > /dev/null && ok "Health OK" || fail "Health 失败"

    echo "⑤ 备份时效..."
    LATEST_BACKUP=$(find data/backups -name "converge-*.sql.gz" -mtime -1 2>/dev/null | head -1)
    if [ -n "$LATEST_BACKUP" ]; then
        ok "最近备份: $(basename "$LATEST_BACKUP")"
    else
        warn "24h 内无备份！"
    fi

    echo "⑥ PortProxy..."
    if command -v netsh &>/dev/null 2>&1; then
        netsh interface portproxy show all 2>/dev/null | grep -q "8080" && fail "8080 被 portproxy 劫持" || ok "无劫持"
    else
        ok "非 Windows, 跳过"
    fi

    echo ""
    [ "$FAILS" -eq 0 ] && echo "✅ Preflight 通过 — 可以部署" || echo "❌ $FAILS 项检查未通过"
}

# ═══════════════════════════════════════
# dispatch
# ═══════════════════════════════════════
case "$CMD" in
    status)    status ;;
    logs)      logs "$@" ;;
    diagnose)  diagnose ;;
    backup)    backup "$@" ;;
    preflight) preflight ;;
    *)
        echo "用法: bash scripts/ops-panel.sh [status|logs|diagnose|backup|preflight]"
        exit 1
        ;;
esac
