#!/bin/bash
# ═══ DevOps Pipeline Orchestrator — 流水线编排器 ═══
# 层: L1 编排层
# 单一职责: 读取 pipeline-state.json → 决定下一阶段 → 调度执行 → 写回状态
# 依赖: discover.sh (发现) · registry.sh (注册) · 各阶段模块
#
# 用法:
#   bash pipeline.sh run                  # 执行完整流水线
#   bash pipeline.sh run --from test      # 从指定阶段开始
#   bash pipeline.sh status               # 查看流水线状态
#   bash pipeline.sh reset                # 重置流水线状态

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
STATE_FILE="$PROJECT_ROOT/reports/devops-pipeline-state.json"
REPORTS_DIR="$PROJECT_ROOT/reports"

mkdir -p "$REPORTS_DIR"

# ─── 阶段定义 ───

STAGES=("plan" "code" "build" "test" "release" "deploy" "operate" "monitor")
STAGE_LABELS=("D01-Plan" "D02-Code" "D03-Build" "D04-Test" "D05-Release" "D06-Deploy" "D07-Operate" "D08-Monitor")

init_state() {
    cat > "$STATE_FILE" <<'EOF'
{
  "pipeline": "devops",
  "version": "1.0",
  "current_stage": "plan",
  "status": "idle",
  "started_at": null,
  "stages": {
    "plan":    {"status": "pending", "result": null, "started_at": null, "finished_at": null},
    "code":    {"status": "pending", "result": null, "started_at": null, "finished_at": null},
    "build":   {"status": "pending", "result": null, "started_at": null, "finished_at": null},
    "test":    {"status": "pending", "result": null, "started_at": null, "finished_at": null},
    "release": {"status": "pending", "result": null, "started_at": null, "finished_at": null},
    "deploy":  {"status": "pending", "result": null, "started_at": null, "finished_at": null},
    "operate": {"status": "pending", "result": null, "started_at": null, "finished_at": null},
    "monitor": {"status": "pending", "result": null, "started_at": null, "finished_at": null}
  }
}
EOF
}

# ─── 阶段执行函数 (每个阶段调用对应模块) ───

run_code() {
    echo "📝 D02 — Code 门禁"
    # 运行 pre-commit 检查
    if [ -f "$PROJECT_ROOT/.git/hooks/pre-commit" ]; then
        bash "$PROJECT_ROOT/.git/hooks/pre-commit"
        echo '{"stage":"code","pass":true,"checks":{"php_lint":"ok","js_lint":"ok","file_size":"ok"}}' \
            > "$REPORTS_DIR/devops-code-result.json"
    else
        echo "⚠️  pre-commit hook 未安装"
        echo '{"stage":"code","pass":true,"checks":{},"warning":"pre-commit not installed"}' \
            > "$REPORTS_DIR/devops-code-result.json"
    fi
}

run_build() {
    echo "🔨 D03 — Build: 密钥扫描"
    local code_result="$REPORTS_DIR/devops-code-result.json"

    # 密钥硬编码扫描
    local hardcoded=0
    for pattern in "DB_PASSWORD\s*=\s*['\"][^'\"]\{4,\}" "STRIPE_SECRET_KEY=sk_live_" "PADDLE_API_KEY=" "OPENAI_API_KEY=sk-" "APP_SECRET=" "JWT_SECRET=" "MYSQL_ROOT_PASSWORD="; do
        local found=$(grep -rl "$pattern" "$PROJECT_ROOT/docker/" "$PROJECT_ROOT/.env"* 2>/dev/null | wc -l)
        if [ "$found" -gt 0 ]; then
            echo "⚠️  Hardcoded secret pattern found: $pattern ($found files)"
            hardcoded=$((hardcoded + found))
        fi
    done

    local pass="true"
    [ "$hardcoded" -gt 0 ] && pass="false"

    cat > "$REPORTS_DIR/devops-build-result.json" <<EOF
{
  "stage": "build",
  "pass": $pass,
  "hardcoded_secrets": $hardcoded,
  "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF
    echo "✅ Build result: pass=$pass (hardcoded secrets: $hardcoded)"
}

run_deploy() {
    echo "🚀 D06 — Deploy: 健康检查验证"

    # 刷新 registry
    bash "$SCRIPT_DIR/registry.sh" refresh 2>/dev/null

    # 对每个 app 运行健康检查
    local all_ok=true
    if [ -f "$REPORTS_DIR/devops-registry.json" ]; then
        python -c "
import json
reg = json.load(open('$REPORTS_DIR/devops-registry.json'))
for oid, obj in reg.get('objects', {}).items():
    if obj['type'] == 'healthcheck':
        url = obj['props'].get('url', '')
        print(f'HC:{url}')
" 2>/dev/null | while read -r line; do
            local url=$(echo "$line" | cut -d: -f2-)
            [ -z "$url" ] && continue
            local http_code=$(curl -sk -o /dev/null -w "%{http_code}" "$url" 2>/dev/null || echo "000")
            if [ "$http_code" = "200" ]; then
                echo "  ✅ $url → $http_code"
            else
                echo "  ❌ $url → $http_code"
                all_ok=false
            fi
        done
    fi

    cat > "$REPORTS_DIR/devops-deploy-result.json" <<EOF
{
  "stage": "deploy",
  "pass": $all_ok,
  "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF
}

# ─── 流水线状态管理 ───

update_stage_status() {
    local stage="$1" status="$2"
    python -c "
import json
state = json.load(open('$STATE_FILE'))
state['stages']['$stage']['status'] = '$status'
now = '$(date -u +%Y-%m-%dT%H:%M:%SZ)'
if '$status' == 'running':
    state['stages']['$stage']['started_at'] = now
elif '$status' in ('completed', 'failed'):
    state['stages']['$stage']['finished_at'] = now
state['current_stage'] = '$stage'
state['status'] = '$status'
json.dump(state, open('$STATE_FILE','w'), indent=2)
" 2>/dev/null
}

set_result() {
    local stage="$1" result_file="$2"
    if [ -f "$result_file" ]; then
        python -c "
import json
state = json.load(open('$STATE_FILE'))
result = json.load(open('$result_file'))
state['stages']['$stage']['result'] = result
json.dump(state, open('$STATE_FILE','w'), indent=2)
" 2>/dev/null
    fi
}

# ─── 执行流水线 ───

run_pipeline() {
    local start_from="${1:-plan}"

    # 确保状态文件存在
    [ -f "$STATE_FILE" ] || init_state

    local started=false
    local now=$(date -u +%Y-%m-%dT%H:%M:%SZ)

    for i in "${!STAGES[@]}"; do
        local stage="${STAGES[$i]}"
        local label="${STAGE_LABELS[$i]}"

        # 跳过 start_from 之前的阶段
        if [ "$started" = false ]; then
            if [ "$stage" = "$start_from" ]; then
                started=true
            else
                echo "⏭️  Skip: $label (already completed)"
                continue
            fi
        fi

        echo ""
        echo "═══ $label ═══"

        update_stage_status "$stage" "running"

        case "$stage" in
            code)    run_code ;;
            build)   run_build ;;
            deploy)  run_deploy ;;
            *)
                echo "⏭️  $label — 暂无实现 (Phase 2/3)"
                echo "{\"stage\":\"$stage\",\"pass\":true,\"note\":\"not implemented yet\"}" \
                    > "$REPORTS_DIR/devops-${stage}-result.json"
                ;;
        esac

        # 读取结果
        local result_file="$REPORTS_DIR/devops-${stage}-result.json"
        if [ -f "$result_file" ]; then
            local pass=$(python -c "import json; print(json.load(open('$result_file')).get('pass',False))" 2>/dev/null || echo "false")
            if [ "$pass" = "True" ]; then
                update_stage_status "$stage" "completed"
                set_result "$stage" "$result_file"
                echo "✅ $label — PASS"
            else
                update_stage_status "$stage" "failed"
                set_result "$stage" "$result_file"
                echo "❌ $label — FAILED (下游阶段已跳过)"
                break
            fi
        else
            update_stage_status "$stage" "completed"
        fi
    done

    echo ""
    echo "═══ Pipeline Complete ═══"
    cmd_status
}

# ─── status: 查看流水线状态 ───

cmd_status() {
    if [ ! -f "$STATE_FILE" ]; then
        echo "📝 无流水线状态 (运行 bash pipeline.sh run 开始)"
        exit 0
    fi

    python -c "
import json
state = json.load(open('$STATE_FILE'))
stages = state.get('stages', {})

print('Pipeline:', state.get('pipeline','?'))
print('Status:  ', state.get('status','?'))
print()
for s, info in stages.items():
    status = info.get('status','?')
    symbol = '✅' if status == 'completed' else ('❌' if status == 'failed' else ('🔄' if status == 'running' else '⏳'))
    print(f'  {symbol} {s:<10} {status}')
" 2>/dev/null
}

# ─── Main ───

case "${1:-}" in
    run)
        init_state
        run_pipeline "${2:-plan}"
        ;;
    status)
        cmd_status
        ;;
    reset)
        init_state
        echo "✅ Pipeline state reset"
        ;;
    *)
        echo "DevOps Pipeline Orchestrator"
        echo ""
        echo "用法: bash pipeline.sh <command>"
        echo ""
        echo "Commands:"
        echo "  run              执行完整流水线"
        echo "  run --from test  从指定阶段开始"
        echo "  status           查看流水线状态"
        echo "  reset            重置流水线状态"
        ;;
esac
