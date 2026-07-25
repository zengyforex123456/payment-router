#!/bin/bash
# ═══ D0V: 统一环境变量管理 — 单一真相源 → dokku config:set ═══
# 单一职责: 读取项目 .env.vars.json → SSH 安全注入所有环境变量 → 验证
# 用法: bash sync-env.sh [project-name]
# 层: L2 执行层 | 依赖: _server-inject.py

set -euo pipefail
HOST="${HOST:-137.184.225.93}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PYTHON=$(command -v python 2>/dev/null || command -v python3 2>/dev/null || echo "python3")

# Convert /e/project/... → E:/project/... for Windows Python compatibility
win_path() { echo "$1" | sed 's|^/\([a-z]\)/|\U\1:/|'; }

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

sync_project() {
    local project="$1"
    local vars_file="/e/project/$project/.env.vars.json"
    local vars_file_win
    vars_file_win=$(win_path "$vars_file")

    [ -f "$vars_file" ] || { echo -e "${RED}missing${NC}: .env.vars.json for $project"; return 1; }

    # 读取 vars.json (非敏感 + 敏感引用)
    local app_name sensitive_count total
    app_name=$($PYTHON -c "import json; print(json.load(open('$vars_file_win'))['app_name'])" 2>/dev/null)
    sensitive_count=$($PYTHON -c "import json; print(len(json.load(open('$vars_file_win')).get('sensitive',[])))" 2>/dev/null)
    total=$($PYTHON -c "import json; d=json.load(open('$vars_file_win')); print(len(d.get('vars',{})) + len(d.get('sensitive',[])))" 2>/dev/null)

    echo ""
    echo "🔧 $project → $app_name"
    echo "   vars: $total ($sensitive_count sensitive)"

    # Step 1: 注入非敏感变量
    local non_sensitive
    non_sensitive=$($PYTHON -c "
import json
d = json.load(open('$vars_file_win'))
sensitive = set(d.get('sensitive', []))
vars_dict = d.get('vars', {})
non = {k: v for k, v in vars_dict.items() if k not in sensitive}
for k, v in non.items():
    print(f'{k}={v}')
" 2>/dev/null)

    if [ -n "$non_sensitive" ]; then
        echo "$non_sensitive" | while IFS='=' read -r key value; do
            [ -z "$key" ] && continue
            ssh "root@${HOST}" "dokku config:set --no-restart $app_name $key=$value" 2>&1 | grep -q "Setting\|already" && \
                echo "   ✅ $key" || echo "   ❌ $key"
        done
    fi

    # Step 2: 注入敏感变量 (通过 Python 安全通道)
    local sensitive_keys
    sensitive_keys=$($PYTHON -c "
import json
d = json.load(open('$vars_file_win'))
print(','.join(d.get('sensitive', [])))
" 2>/dev/null)

    if [ -n "$sensitive_keys" ]; then
        # 用 _server-inject.py 安全注入
        local env_source
        env_source=$($PYTHON -c "import json; print(json.load(open('$vars_file_win')).get('env_source',''))" 2>/dev/null)

        if [ -n "$env_source" ] && [ -f "$env_source" ]; then
            ssh "root@${HOST}" "python3 /root/_server-inject.py $app_name /dev/stdin $sensitive_keys" < "$env_source" 2>&1 | \
                while read line; do
                    case "$line" in
                        OK*) echo "   🔐 ${line}" ;;
                        FAIL*) echo -e "   ${RED}${line}${NC}" ;;
                    esac
                done
        else
            echo -e "   ${YELLOW}⚠️${NC} env_source not found, skipping sensitive vars"
        fi
    fi

    # Step 3: 验证
    echo "   ────────────────"
    local ok=0 fail=0
    for key in $($PYTHON -c "
import json
d = json.load(open('$vars_file_win'))
sensitive = set(d.get('sensitive', []))
all_keys = list(d.get('vars', {}).keys()) + d.get('sensitive', [])
print(' '.join(all_keys))
" 2>/dev/null); do
        if ssh "root@${HOST}" "dokku config:get $app_name $key > /dev/null 2>&1 && echo set || echo not" 2>/dev/null | grep -q "set"; then
            ok=$((ok + 1))
        else
            echo "   ❌ verify: $key missing"
            fail=$((fail + 1))
        fi
    done
    echo -e "   ${GREEN}✅ $ok verified${NC}  ${RED}❌ $fail missing${NC}"

    # Step 4: EventStore — 记录配置变更
    local events_file="$SCRIPT_DIR/../../reports/devops-events.jsonl"
    mkdir -p "$(dirname "$events_file")"
    echo "{\"type\":\"config.synced\",\"app\":\"$app_name\",\"env_source\":\"${env_source:-none}\",\"sensitive_count\":${sensitive_count},verified\":$ok,\"missing\":$fail,\"timestamp\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\"}" >> "$events_file" 2>/dev/null || true
}

# ─── Main ───
echo "╔═══════════════════════════════════════╗"
echo "║  D0V: 环境变量同步 → Dokku          ║"
echo "╚═══════════════════════════════════════╝"

if [ $# -gt 0 ]; then
    sync_project "$1"
else
    # 扫描所有项目
    for d in /e/project/*/; do
        project=$(basename "$d")
        [ "$project" = "deploy-manager" ] && continue
        [ -f "$d.env.vars.json" ] && sync_project "$project"
    done
fi

echo ""
echo "✅ D0V complete"
