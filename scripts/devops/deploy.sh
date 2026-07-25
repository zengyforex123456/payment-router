#!/bin/bash
# ═══ Converge DevOps — 一键部署 ═══
# 单一职责: 从检查→注入→推送→验证 全自动化
# 用法: bash deploy.sh <project-name>
#       bash deploy.sh converge
#       bash deploy.sh adscope
#       bash deploy.sh payment-router
set -euo pipefail

HOST="${HOST:-137.184.225.93}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PYTHON=$(command -v python 2>/dev/null || command -v python3 2>/dev/null || echo "python3")
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'

# Convert /e/project/... → E:/project/... for Windows Python compatibility
win_path() { echo "$1" | sed 's|^/\([a-z]\)/|\U\1:/|'; }

PROJECT="$1"
PROJECT_DIR="/e/project/${PROJECT}"
PROJECT_DIR_WIN=$(win_path "$PROJECT_DIR")

[ -d "$PROJECT_DIR" ] || { echo -e "${RED}❌ 项目不存在: $PROJECT_DIR${NC}"; exit 1; }

echo "╔═══════════════════════════════════════╗"
echo "║  Converge DevOps — 一键部署          ║"
echo "║  项目: $PROJECT"
echo "║  服务器: $HOST"
echo "╚═══════════════════════════════════════╝"

# ─── Phase 1: 部署前检查 ───
echo -e "\n${CYAN}[1/4] 部署前检查...${NC}"
bash "$SCRIPT_DIR/pre-deploy-check.sh" "$PROJECT_DIR"
if [ $? -ne 0 ]; then
    echo -e "${RED}❌ 部署前检查失败，修复后重试${NC}"
    exit 1
fi

# ─── Phase 2: 环境变量同步 ───
echo -e "\n${CYAN}[2/4] 同步环境变量...${NC}"
if [ -f "$PROJECT_DIR/.env.vars.json" ]; then
    bash "$SCRIPT_DIR/sync-env.sh" "$PROJECT"
else
    echo -e "${YELLOW}⚠️  无 .env.vars.json，跳过${NC}"
fi

# ─── Phase 3: Git Push ───
echo -e "\n${CYAN}[3/4] 推送部署...${NC}"
cd "$PROJECT_DIR"
BRANCH=$(git branch --show-current)
PREV_COMMIT=$(ssh "root@${HOST}" "dokku git:report ${APP_NAME:-$PROJECT} 2>/dev/null" | grep "Git deploy rev" | cut -d: -f2- | tr -d ' ' 2>/dev/null || echo "")
echo "   分支: $BRANCH → dokku:main"
echo "   上次部署: ${PREV_COMMIT:-未知}"
git push dokku "$BRANCH":main 2>&1 | grep -E 'Building|Deploying|Deployed|Error|FAIL' || true
NEW_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "")

# EventStore: 记录部署事件
EVENTS_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)/reports"
mkdir -p "$EVENTS_DIR"
echo "{\"type\":\"deployment.completed\",\"app\":\"${APP_NAME:-$PROJECT}\",\"commit\":\"$NEW_COMMIT\",\"branch\":\"$BRANCH\",\"previous_commit\":\"$PREV_COMMIT\",\"timestamp\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\"}" >> "$EVENTS_DIR/devops-events.jsonl" 2>/dev/null || true

# ─── Phase 4: 部署后验证 ───
echo -e "\n${CYAN}[4/4] 部署后验证...${NC}"

# 4.1 等待容器启动
echo "   等待容器就绪..."
sleep 15

# 4.2 读取域名
DOMAIN=""
if [ -f "$PROJECT_DIR/.deploy.json" ]; then
    DOMAIN=$($PYTHON -c "import json; print(json.load(open('$PROJECT_DIR_WIN/.deploy.json')).get('domain',''))" 2>/dev/null || echo "")
fi
APP_NAME=""
if [ -f "$PROJECT_DIR/.env.vars.json" ]; then
    APP_NAME=$($PYTHON -c "import json; print(json.load(open('$PROJECT_DIR_WIN/.env.vars.json')).get('app_name',''))" 2>/dev/null || echo "")
fi
[ -z "$APP_NAME" ] && APP_NAME="$PROJECT"

# 4.3 容器状态
echo "   容器状态:"
ssh "root@${HOST}" "docker ps --filter name=${APP_NAME} --format '   {{.Names}}: {{.Status}}'" 2>/dev/null || echo "   ⚠️ 无法检查"

# 4.4 HTTP 验证
if [ -n "$DOMAIN" ]; then
    echo "   HTTP 检查:"
    HTTP_CODE=$(curl -sk -o /dev/null -w '%{http_code}' "https://$DOMAIN/" --max-time 10 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "302" ]; then
        echo -e "   ${GREEN}✅ $DOMAIN → HTTP $HTTP_CODE${NC}"
    else
        echo -e "   ${RED}❌ $DOMAIN → HTTP $HTTP_CODE${NC}"
        echo "   检查日志:"
        ssh "root@${HOST}" "docker logs ${APP_NAME}.web.1 --tail 5" 2>/dev/null || true
    fi
fi

# 4.5 健康检查
HEALTH_PATH="/health"
if [ -f "$PROJECT_DIR/.deploy.json" ]; then
    HEALTH_PATH=$($PYTHON -c "import json; d=json.load(open('$PROJECT_DIR_WIN/.deploy.json')); print(d.get('services',[{}])[0].get('health','/health'))" 2>/dev/null || echo "/health")
fi
if [ -n "$DOMAIN" ]; then
    HEALTH_CODE=$(curl -sk -o /dev/null -w '%{http_code}' "https://$DOMAIN$HEALTH_PATH" --max-time 10 2>/dev/null || echo "000")
    echo "   健康检查 ($HEALTH_PATH): HTTP $HEALTH_CODE"
fi

# ─── 资源限制 ───
echo -e "\n${CYAN}[可选的] 资源限制...${NC}"
echo "   ssh root@${HOST} 'dokku resource:set ${APP_NAME} memory 256M'"

echo ""
echo "╔═══════════════════════════════════════╗"
echo "║  🎉 部署完成                          ║"
echo "╚═══════════════════════════════════════╝"
[ -n "$DOMAIN" ] && echo "   https://$DOMAIN"
echo ""
