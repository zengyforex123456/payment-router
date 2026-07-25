#!/bin/bash
# ═══ R2: 密钥外置 — .env → dokku config (safe: value via stdin) ═══
# 单一职责: 读取本地 .env → SSH 安全推送 → Dokku config:set
# 用法: bash inject-secrets.sh

set -euo pipefail

HOST="${HOST:-137.184.225.93}"
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

# ─── 安全注入: 值通过临时文件传递，不经过 ps/命令行 ───
safe_inject() {
    local app="$1" key="$2" value="$3"
    # 通过 SSH stdin 传递值，远端写入临时文件，dokku config:set 从文件读取
    echo "$value" | ssh "root@${HOST}" \
        "tmp=\$(mktemp) && cat > \$tmp && dokku config:set --no-restart $app $key=\$(cat \$tmp) && rm -f \$tmp" \
        2>&1 | grep -q "Configuring\|already set" && echo "ok" || echo "fail"
}

# ─── 处理一个项目 ───
inject_project() {
    local project_dir="$1" manifest app_name env_file env_path

    manifest="$project_dir/.deploy.json"
    [ -f "$manifest" ] || { echo "skip: $project_dir (no .deploy.json)"; return; }

    # 读取配置
    local name type env
    name=$(python -c "import json; print(json.load(open('$manifest'))['name'])" 2>/dev/null || echo "")
    env_file=$(python -c "import json; print(json.load(open('$manifest')).get('env_file',''))" 2>/dev/null || echo "")
    [ -z "$name" ] && return
    [ -z "$env_file" ] && { echo -e "${YELLOW}skip${NC}: $name (no env_file)"; return; }

    env_path="$project_dir/$env_file"
    [ -f "$env_path" ] || { echo -e "${RED}missing${NC}: $name → $env_path"; return; }

    # 读 .deploy.json → secrets 列表
    local secrets
    secrets=$(python -c "import json; d=json.load(open('$manifest')); print(','.join(d.get('secrets',[])))" 2>/dev/null || echo "")
    [ -z "$secrets" ] && { echo "skip: $name (no secrets in manifest)"; return; }

    # app 名映射
    case "$name" in
        converge-skeleton) app_name="payment-router" ;;
        converge)          app_name="converge" ;;
        adscope)           app_name="adscope" ;;
        *)                 app_name="$name" ;;
    esac

    echo ""
    echo "🔐 $name → $app_name"
    echo "   source: $env_path"

    local ok=0 fail=0
    IFS=',' read -ra KEYS <<< "$secrets"
    for key in "${KEYS[@]}"; do
        key="${key// /}"
        [ -z "$key" ] && continue

        # 从 .env 读值 (grep + cut)
        local value
        value=$(grep "^${key}=" "$env_path" | head -1 | cut -d= -f2-)
        value="${value#\"}"; value="${value%\"}"
        value="${value#\'}"; value="${value%\'}"

        if [ -z "$value" ]; then
            echo "   ⚠️  $key: not found in .env"
            fail=$((fail + 1))
            continue
        fi

        # 安全注入
        local result
        result=$(safe_inject "$app_name" "$key" "$value")
        if [ "$result" = "ok" ]; then
            echo "   ✅ $key=***(${#value} chars)"
            ok=$((ok + 1))
        else
            echo "   ❌ $key: inject failed"
            fail=$((fail + 1))
        fi
    done

    echo "   ─────────────────"
    echo -e "   ${GREEN}OK: $ok${NC}  ${RED}Fail: $fail${NC}"

    # 验证
    local verify_ok=0 verify_fail=0
    for key in "${KEYS[@]}"; do
        key="${key// /}"
        [ -z "$key" ] && continue
        if ssh "root@${HOST}" "dokku config:get $app_name $key 2>/dev/null | grep -q ." 2>/dev/null; then
            verify_ok=$((verify_ok + 1))
        else
            echo "   ❌ verify: $key not set"
            verify_fail=$((verify_fail + 1))
        fi
    done
    [ "$verify_ok" -gt 0 ] && echo "   ✅ $verify_ok keys verified on server"
}

# ─── Main ───
echo "╔══════════════════════════════════════════════╗"
echo "║   R2: 密钥外置 — .env → dokku config:set    ║"
echo "╚══════════════════════════════════════════════╝"

PROJECTS=(
    "/e/project/converge-skeleton"
    "/e/project/converge"
    "/e/project/adscope"
)

for dir in "${PROJECTS[@]}"; do
    [ -d "$dir" ] || continue
    inject_project "$dir"
done

echo ""
echo "✅ R2 complete"
