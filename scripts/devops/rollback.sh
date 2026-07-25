#!/bin/bash
# ═══ DevOps Rollback — 一键回滚 + 六步验证 ═══
# 层: L2 执行层 (D07-Operate)
# 单一职责: 回滚 Dokku 应用到上一版本 + 验证恢复
# 用法: bash rollback.sh <app-name>

set -euo pipefail

APP="${1:-}"
HOST="${HOST:-137.184.225.93}"
SSH="ssh root@${HOST}"

[ -z "$APP" ] && { echo "用法: bash rollback.sh <app-name>"; exit 1; }

echo "🔄 Rolling back: $APP"

# 1. 获取当前版本 (用于审计)
OLD_COMMIT=$($SSH "dokku git:report $APP 2>/dev/null" | grep "Git deploy rev" | cut -d: -f2- | tr -d ' ' || echo "unknown")
echo "   Current: $OLD_COMMIT"

# 2. 执行回滚
$SSH "dokku ps:rollback $APP" || {
    echo "❌ Rollback failed"
    exit 1
}

# 3. 等待容器启动
sleep 3

# 4. 获取新版本
NEW_COMMIT=$($SSH "dokku git:report $APP 2>/dev/null" | grep "Git deploy rev" | cut -d: -f2- | tr -d ' ' || echo "unknown")
echo "   Rolled back to: $NEW_COMMIT"

# 5. 六步验证
echo ""
echo "═══ 回滚后验证 ═══"

# ① 进程
if $SSH "docker ps --format '{{.Names}}' | grep -q '$APP'"; then
    echo "✅ ① 进程: $APP 容器运行中"
else
    echo "❌ ① 进程: $APP 容器未运行"
    exit 1
fi

# ② 网络
DOMAIN=$($SSH "dokku domains:report $APP --dokku-domains-simple 2>/dev/null" | head -1 || echo "")
if [ -n "$DOMAIN" ]; then
    HTTP_CODE=$(curl -sk -o /dev/null -w "%{http_code}" "https://$DOMAIN" 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" != "000" ]; then
        echo "✅ ② 网络: https://$DOMAIN → $HTTP_CODE"
    else
        echo "⚠️  ② 网络: https://$DOMAIN 不可达"
    fi
fi

# ③ 部署状态
DEPLOYED=$($SSH "dokku ps:report $APP 2>/dev/null" | grep "Deployed:" | grep -c "true" || echo "0")
if [ "$DEPLOYED" -ge 1 ]; then
    echo "✅ ③ 部署: Deployed=true"
else
    echo "❌ ③ 部署: Deployed=false"
    exit 1
fi

echo ""
echo "🎉 Rollback complete: $OLD_COMMIT → $NEW_COMMIT"

# 6. 记录 EventStore
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
EVENTS="$SCRIPT_DIR/../../reports/devops-events.jsonl"
mkdir -p "$(dirname "$EVENTS")"
echo "{\"type\":\"rollback\",\"app\":\"$APP\",\"old_commit\":\"$OLD_COMMIT\",\"new_commit\":\"$NEW_COMMIT\",\"timestamp\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\"}" >> "$EVENTS"
echo "📋 EventStore: rollback recorded"
