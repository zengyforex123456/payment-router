#!/bin/bash
# ═══ Dokku DevOps Timeline — 部署事件时间线 ═══
# 层: L4 横切层 (可追溯)
# 单一职责: 合并 git log + EventStore + KAG → 时间线视图
# 用法: bash timeline.sh [--app <name>] [--since 7d] [--json]
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJ_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
REPORTS="$PROJ_ROOT/reports"
HOST="${HOST:-137.184.225.93}"

FILTER_APP=""; SINCE=""; JSON_OUT=false
while [ $# -gt 0 ]; do
    case "$1" in
        --app) FILTER_APP="$2"; shift ;;
        --since) SINCE="$2"; shift ;;
        --json) JSON_OUT=true ;;
    esac
    shift
done

# ─── 图标映射 ───
icon() {
    case "$1" in
        deployment*)   echo "🚀" ;;
        config*)       echo "🔐" ;;
        backup*)       echo "📦" ;;
        recovered*|heal*) echo "🩹" ;;
        rollback*)     echo "⏪" ;;
        outage*|error*|fail*) echo "❌" ;;
        *)             echo "📌" ;;
    esac
}

# ─── 收集事件 ───
events=""

# 来源 1: EventStore
if [ -f "$REPORTS/devops-events.jsonl" ]; then
    while IFS= read -r line; do
        ts=$(echo "$line" | python -c "import sys,json; print(json.loads(sys.stdin.read()).get('timestamp',''))" 2>/dev/null || echo "")
        type=$(echo "$line" | python -c "import sys,json; print(json.loads(sys.stdin.read()).get('type','?'))" 2>/dev/null || echo "?")
        app=$(echo "$line" | python -c "import sys,json; print(json.loads(sys.stdin.read()).get('app',''))" 2>/dev/null || echo "")
        [ -n "$ts" ] && events+="$ts|$type|$app|$(icon "$type")|$line\n"
    done < "$REPORTS/devops-events.jsonl"
fi

# 来源 2: KAG entities
if [ -f "$PROJ_ROOT/data/kag-entities.jsonl" ]; then
    while IFS= read -r line; do
        ts=$(echo "$line" | python -c "import sys,json; print(json.loads(sys.stdin.read()).get('created_at',''))" 2>/dev/null || echo "")
        title=$(echo "$line" | python -c "import sys,json; print(json.loads(sys.stdin.read()).get('title','?'))" 2>/dev/null || echo "?")
        [ -n "$ts" ] && events+="$ts|knowledge|system|🧠|$title\n"
    done < "$PROJ_ROOT/data/kag-entities.jsonl"
fi

# 来源 3: Git log (本地)
if git -C "$PROJ_ROOT" log --oneline -1 >/dev/null 2>&1; then
    while IFS= read -r line; do
        commit=$(echo "$line" | cut -d' ' -f1)
        msg=$(echo "$line" | cut -d' ' -f2-)
        ts=$(git -C "$PROJ_ROOT" log -1 --format=%aI "$commit" 2>/dev/null || echo "")
        [ -n "$FILTER_APP" ] && ! echo "$msg" | grep -qi "$FILTER_APP" && continue
        [ -n "$ts" ] && events+="$ts|git|devops|📝|$commit $msg\n"
    done <<< "$(git -C "$PROJ_ROOT" log --oneline -30 2>/dev/null)"
fi

# ─── 排序 + 过滤 + 渲染 ───
if [ -z "$events" ]; then
    echo "📝 暂无事件 (运行 bash scripts/devops/registry.sh refresh 初始化)"
    exit 0
fi

echo ""
echo "══════════════════════════════════════════════════════════════"
echo "  DevOps 事件时间线"
[ -n "$FILTER_APP" ] && echo "  过滤: $FILTER_APP"
[ -n "$SINCE" ] && echo "  范围: $SINCE"
echo "══════════════════════════════════════════════════════════════"

# 按时间倒序
echo -e "$events" | sort -r | head -40 | while IFS='|' read -r ts type app emoji detail; do
    [ -z "$ts" ] && continue
    [ -n "$FILTER_APP" ] && [ "$app" != "$FILTER_APP" ] && [ "$app" != "system" ] && continue
    local_ts=$(date -d "$ts" '+%Y-%m-%d %H:%M' 2>/dev/null || echo "$ts")
    printf "%-18s %s %-12s %-12s %s\n" "$local_ts" "$emoji" "$app" "$type" "$(echo "$detail" | cut -c1-60)"
done

echo "══════════════════════════════════════════════════════════════"
echo ""
