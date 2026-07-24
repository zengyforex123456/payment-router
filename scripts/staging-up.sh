#!/bin/bash
# staging-up.sh — Converge Staging 一键部署
# 用法:
#   bash scripts/staging-up.sh             启动 staging（默认 8080）
#   bash scripts/staging-up.sh --down      关闭 staging
#   bash scripts/staging-up.sh --rebuild   重建 + 启动
#   bash scripts/staging-up.sh --logs      查看日志
#   bash scripts/staging-up.sh --status    状态检查
set -euo pipefail
cd "$(dirname "$0")/.."

GREEN='\033[0;32m'; RED='\033[0;31m'; CYAN='\033[0;36m'; NC='\033[0m'
CMD="${1:-}"

staging_up() {
    echo "🚀 启动 Converge Staging (端口 8080)..."
    docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d --wait 2>&1

    echo ""
    echo "⏳ 等待健康检查..."
    for i in $(seq 1 30); do
        CODE=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 3 http://localhost:8080/health 2>/dev/null || echo "000")
        if [ "$CODE" = "200" ]; then
            echo -e "  ${GREEN}✅ Health: 200 (${i}s)${NC}"
            break
        fi
        sleep 1
    done

    echo ""
    echo "═══════════════════════════════════════"
    echo "  Staging 已就绪"
    echo "  http://localhost:8080/health"
    echo "  http://localhost:8080/landing.php"
    echo "═══════════════════════════════════════"
}

staging_down() {
    echo "🛑 关闭 Staging..."
    docker compose -f docker-compose.yml -f docker-compose.staging.yml down -v 2>&1
    echo -e "  ${GREEN}✅ Staging 已关闭${NC}"
}

staging_rebuild() {
    echo "🔨 重建镜像..."
    docker compose -f docker-compose.yml -f docker-compose.staging.yml build --no-cache app 2>&1
    staging_up
}

staging_logs() {
    docker compose -f docker-compose.yml -f docker-compose.staging.yml logs -f --tail=50 app
}

staging_status() {
    echo "════════ Staging 状态 ════════"
    echo ""
    echo "容器:"
    docker compose -f docker-compose.yml -f docker-compose.staging.yml ps 2>&1
    echo ""
    echo "端点:"
    for url in "http://localhost:8080/health" "http://localhost:8080/landing.php"; do
        CODE=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 3 "$url" 2>/dev/null || echo "000")
        if [ "$CODE" = "200" ]; then
            echo -e "  ${GREEN}${CODE}${NC} $url"
        else
            echo -e "  ${RED}${CODE}${NC} $url"
        fi
    done
}

case "$CMD" in
    --down)    staging_down ;;
    --rebuild) staging_rebuild ;;
    --logs)    staging_logs ;;
    --status)  staging_status ;;
    --help|-h)
        echo "用法: bash scripts/staging-up.sh [--down|--rebuild|--logs|--status]"
        ;;
    *)         staging_up ;;
esac
