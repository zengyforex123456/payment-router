#!/bin/bash
# ═══ GitOps Reconciliation Controller ═══
# 单一职责: 读 Git 中声明的目标状态 → 对比 Dokku 实际状态 → 自动修正漂移
# 用法: bash reconcile.sh [--dry-run] [--auto-fix]
# cron: */10 * * * * bash /path/to/reconcile.sh --auto-fix
set -euo pipefail

HOST="${HOST:-137.184.225.93}"
SSH="ssh root@${HOST}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DRY_RUN=false; AUTO_FIX=false
[ "${1:-}" = "--dry-run" ] && DRY_RUN=true
[ "${2:-}" = "--auto-fix" ] && AUTO_FIX=true
$DRY_RUN && AUTO_FIX=false

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
drift=0; fixed=0

reconcile() {
    local resource="$1" desired="$2" actual="$3" fix_cmd="$4"

    if [ "$desired" = "$actual" ]; then
        echo -e "  ${GREEN}✅${NC} $resource: $desired"
        return 0
    fi

    drift=$((drift + 1))
    echo -e "  ${YELLOW}🔀${NC} $resource: desired=$desired actual=$actual"

    if $AUTO_FIX; then
        echo -e "  ${GREEN}🔧${NC} Auto-fixing: $fix_cmd"
        $SSH "$fix_cmd" 2>/dev/null && fixed=$((fixed + 1)) || echo -e "  ${RED}❌${NC} Fix failed"
    elif ! $DRY_RUN; then
        echo -e "    Fix: $fix_cmd"
    fi
}

echo "╔═══════════════════════════════════════╗"
echo "║  GitOps Reconciliation               ║"
echo "║  Desired state: Git (.deploy.json)    ║"
echo "║  Actual state: Dokku Server           ║"
echo "╚═══════════════════════════════════════╝"
echo ""

# ─── 扫描所有项目 ───
for deploy_json in /e/project/*/.deploy.json; do
    [ -f "$deploy_json" ] || continue
    proj=$(basename "$(dirname "$deploy_json")")
    python -c "
import json; d=json.load(open('$deploy_json'))
print(d.get('name','') + '|' + d.get('domain','') + '|' + str(d.get('deployed',False)))
" 2>/dev/null | while IFS='|' read -r app domain deployed; do
        [ -z "$app" ] && continue
        echo "── $app ──"

        # 1. App 存在性
        app_exists=$($SSH "dokku apps:list 2>/dev/null | grep -q '^$app$' && echo yes || echo no")
        reconcile "app:$app exists" "yes" "$app_exists" "dokku apps:create $app"

        # 2. 环境变量数量
        vars_file="/e/project/$proj/.env.vars.json"
        if [ -f "$vars_file" ]; then
            desired_count=$(python -c "import json; d=json.load(open('$vars_file')); print(len(d.get('vars',{})) + len(d.get('sensitive',[])))" 2>/dev/null)
            actual_count=$($SSH "dokku config:export $app --format shell 2>/dev/null | grep -cv '^DOKKU_\|^GIT_'" || echo 0)
            reconcile "env:count" "$desired_count" "$actual_count" "bash $SCRIPT_DIR/sync-env.sh $proj"
        fi

        # 3. 域名配置
        if [ -n "$domain" ] && [ "$domain" != "" ]; then
            actual_domain=$($SSH "dokku domains:report $app --dokku-domains-simple 2>/dev/null" | head -1)
            reconcile "domain" "$domain" "$actual_domain" "dokku domains:set $app $domain"
        fi
    done
done

echo ""
echo "──────────────────────────────────────"
echo -e "  Drift: $drift  |  Auto-fixed: $fixed"
echo "──────────────────────────────────────"

if [ "$drift" -eq 0 ]; then
    echo -e "${GREEN}✅ All systems reconciled — no drift detected${NC}"
else
    echo -e "${YELLOW}🔀 $drift drift(s) detected${NC}"
fi
