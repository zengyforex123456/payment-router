#!/bin/bash
# Converge Docker 一键部署
# 用法: bash scripts/docker-up.sh [TAG]
# 环境变量: DB_PASSWORD (必填), IMAGE (可选, 默认 ghcr.io), NGINX_PORT (可选, 默认 80)

set -e
cd "$(dirname "$0")/.."

export TAG="${1:-latest}"
export DB_PASSWORD="${DB_PASSWORD:?请设置 DB_PASSWORD 环境变量}"

echo "═══ Converge Docker Deploy ═══"
echo "Image: ${IMAGE:-ghcr.io/zengyforex123456/converge}:${TAG}"
echo "Port: ${NGINX_PORT:-80}"

docker compose up -d 2>&1

echo "---"
docker compose ps
echo "---"
sleep 5
curl -sf http://127.0.0.1:${NGINX_PORT:-80}/health && echo "✅ Health OK" || echo "⚠️ Health check failed"
