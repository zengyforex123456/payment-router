#!/bin/bash
# docker-local.sh — Converge 本地 Docker 开发环境一键启动
# 用法: bash scripts/docker-local.sh [up|down|rebuild|logs|status]
set -e

cd "$(dirname "$0")/.."

case "${1:-up}" in
  up)
    echo "🚀 Starting Converge (local dev)..."
    cp -n .env.docker .env 2>/dev/null || true
    docker compose -f docker-compose.dev.yml up -d --build
    echo ""
    echo "⏳ Waiting for MySQL readiness..."
    for i in $(seq 1 30); do
      docker compose -f docker-compose.dev.yml exec -T mysql mysqladmin ping -h localhost --silent 2>/dev/null && break
      sleep 1
    done
    echo "✅ Converge ready: http://localhost:8080"
    echo "   Mailpit: http://localhost:8025"
    ;;

  down)
    echo "🛑 Stopping Converge dev..."
    docker compose -f docker-compose.dev.yml down
    ;;

  rebuild)
    echo "🔨 Rebuilding Converge (no cache)..."
    docker compose -f docker-compose.dev.yml build --no-cache
    docker compose -f docker-compose.dev.yml up -d
    ;;

  logs)
    docker compose -f docker-compose.dev.yml logs -f --tail=50
    ;;

  status)
    echo "=== Converge Docker Status ==="
    docker compose -f docker-compose.dev.yml ps
    echo ""
    echo "=== Health Check ==="
    curl -s http://localhost:8080/health 2>/dev/null || echo "(server not running)"
    ;;

  *)
    echo "Usage: bash scripts/docker-local.sh [up|down|rebuild|logs|status]"
    ;;
esac
