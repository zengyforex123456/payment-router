#!/bin/bash
# ═══ 六可综合验证 — 全六可门禁 ═══
# 层: L4 横切层
# 单一职责: 对 registry 中所有对象运行六可验证，输出综合报告
# 用法: bash verify-six-capabilities.sh [--strict]

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
REGISTRY="$PROJECT_ROOT/reports/devops-registry.json"
REPORTS="$PROJECT_ROOT/reports"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

pass_count=0; fail_count=0; warn_count=0
# 默认值 (防止 unbound variable)
health_score=0; hardcoded=0; php_errs=0; module_count=0
events_file="$REPORTS/devops-events.jsonl"
has_rollback_script=""

check() {
    local cap="$1" desc="$2" result="$3"
    case "$result" in
        pass) echo -e "  ${GREEN}✅${NC} $cap: $desc"; pass_count=$((pass_count+1)) ;;
        fail) echo -e "  ${RED}❌${NC} $cap: $desc"; fail_count=$((fail_count+1)) ;;
        warn) echo -e "  ${YELLOW}⚠️${NC}  $cap: $desc"; warn_count=$((warn_count+1)) ;;
    esac
}

echo "╔══════════════════════════════════════════════════════════╗"
echo "║        DevOps 六可综合验证 (Six-Capability Gates)        ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

# ─── 🔭 可观察: 所有对象状态可见 ───
echo "🔭 可观察 (Observability)"
if [ -f "$REGISTRY" ]; then
    total=$(python -c "import json; r=json.load(open('$REGISTRY')); print(r['summary']['total'])" 2>/dev/null || echo "0")
    health_score=$(python -c "import json; r=json.load(open('$REGISTRY')); ok=r['summary']['by_health'].get('ok',0); total=r['summary']['total']; print(int(ok/total*100) if total>0 else 0)" 2>/dev/null || echo "0")
    dark=$(python -c "import json; r=json.load(open('$REGISTRY')); dark=[oid for oid,o in r.get('objects',{}).items() if o.get('health','?')=='error']; print(len(dark))" 2>/dev/null || echo "0")

    if [ "$health_score" -ge 80 ] 2>/dev/null; then
        check "可观察" "健康指数 ${health_score}% (${total} objects, ${dark} dark)" "pass"
    elif [ "$health_score" -ge 60 ] 2>/dev/null; then
        check "可观察" "健康指数 ${health_score}% (${dark} dark objects)" "warn"
    else
        check "可观察" "健康指数 ${health_score}% — 阻塞" "fail"
    fi
else
    health_score=0
    check "可观察" "registry 不存在 — 运行: bash scripts/devops/registry.sh refresh" "fail"
fi

# ─── 📋 可追溯: 任何事件可沿时间线回溯 ───
echo "📋 可追溯 (Traceability)"
if [ -f "$events_file" ]; then
    event_count=$(wc -l < "$events_file" 2>/dev/null || echo "0")
    check "可追溯" "EventStore 有 ${event_count} 条事件" "pass"
else
    check "可追溯" "EventStore 不存在 (首次运行，预期为空)" "warn"
fi

if git log --oneline -1 >/dev/null 2>&1; then
    check "可追溯" "git log 可追溯" "pass"
else
    check "可追溯" "git log 不可达" "fail"
fi

# ─── 📐 可审计: 任何变更可被独立检查 ───
echo "📐 可审计 (Auditability)"
if [ -f "$PROJECT_ROOT/.git/hooks/pre-commit" ]; then
    check "可审计" "pre-commit hook 已安装" "pass"
else
    check "可审计" "pre-commit hook 未安装" "warn"
fi

# 检测真正硬编码的密钥 (排除 getenv/env 读取和空默认值)
hardcoded=0
for f in $(find "$PROJECT_ROOT/docker/" -name "*.php" -o -name "*.sh" -o -name "Dockerfile" 2>/dev/null); do
    # 检测模式: 字符串值中看起来像真实密钥的 (长度 >8, 非 'change-me' / '' / '...')
    if grep -Pn "(?<!getenv\(')(?<!env\(')(?<!config:set )["'](sk_live_|pk_live_|sk-|pdl_)[^"']{8,}["']" "$f" 2>/dev/null | grep -qv "change-me\|example\|your-\|xxx"; then
        hardcoded=$((hardcoded + 1))
    fi
done
# 也用简单方法: 排除 getenv 行
simple_check=$(grep -rn "= '.*sk_live_\|= '.*sk-.*'\|= '.*pdl_.*'" "$PROJECT_ROOT/docker/" 2>/dev/null | grep -v "getenv\|change-me\|example\|your-" | wc -l 2>/dev/null || echo "0")
simple_check=$(echo "$simple_check" | tr -d '[:space:]')
[ -z "$simple_check" ] && simple_check=0

if [ "$hardcoded" -eq 0 ] 2>/dev/null && [ "$simple_check" -eq 0 ] 2>/dev/null; then
    check "可审计" "Docker 文件零明文密钥" "pass"
else
    check "可审计" "发现疑似硬编码密钥 (hardcoded=$hardcoded, simple=$simple_check)" "fail"
fi

# ─── ✅ 可验证: 任何断言可通过实验证伪 ───
echo "✅ 可验证 (Verifiability)"
php_errs=$(find "$PROJECT_ROOT" -name "*.php" \
    -not -path "*/vendor/*" \
    -not -path "*/cache/*" \
    -not -path "*/node_modules/*" \
    -not -path "*/.claude/*" \
    -exec php -l {} \; 2>/dev/null | grep -c "Errors parsing\|Parse error" || echo "0")
if [ "$php_errs" -eq 0 ]; then
    check "可验证" "PHP 语法: 0 errors" "pass"
else
    check "可验证" "PHP 语法: ${php_errs} 个解析错误" "fail"
fi

if [ -f "$REGISTRY" ]; then
    hc_count=$(python -c "import json; r=json.load(open('$REGISTRY')); print(sum(1 for o in r.get('objects',{}).values() if o['type']=='healthcheck'))" 2>/dev/null || echo "0")
    if [ "$hc_count" -gt 0 ]; then
        check "可验证" "${hc_count} 个健康检查已配置" "pass"
    else
        check "可验证" "无健康检查配置" "warn"
    fi
else
    check "可验证" "registry 不存在，无法验证健康检查" "warn"
fi

# ─── 🧬 可进化: 新功能=新文件，不改旧模块 ───
echo "🧬 可进化 (Evolvability)"
module_count=$(ls "$PROJECT_ROOT/.claude/rules/devops-"*.md 2>/dev/null | wc -l || echo "0")
if [ "$module_count" -ge 8 ]; then
    check "可进化" "${module_count} 个独立 DevOps 模块" "pass"
elif [ "$module_count" -ge 4 ]; then
    check "可进化" "${module_count} 个模块 (Phase 2/3 待实施)" "warn"
else
    check "可进化" "${module_count} 个模块" "warn"
fi

# ─── 🩹 可自愈: 故障自动检测恢复 ───
echo "🩹 可自愈 (Self-healing)"
has_rollback_script=$(ls "$SCRIPT_DIR/rollback.sh" 2>/dev/null || echo "")
if [ -n "$has_rollback_script" ]; then
    check "可自愈" "回滚脚本已就绪" "pass"
else
    check "可自愈" "回滚脚本待创建 (Phase 1)" "warn"
fi
# 检查健康检查自愈机制
if [ -f "$REGISTRY" ]; then
    app_count=$(python -c "import json; r=json.load(open('$REGISTRY')); print(sum(1 for o in r.get('objects',{}).values() if o['type']=='app'))" 2>/dev/null || echo "0")
    res_count=$(python -c "import json; r=json.load(open('$REGISTRY')); print(sum(1 for o in r.get('objects',{}).values() if o['type']=='resourcelimit'))" 2>/dev/null || echo "0")
    if [ "$res_count" -ge "$app_count" ] 2>/dev/null && [ "$app_count" -gt 0 ] 2>/dev/null; then
        check "可自愈" "资源限制覆盖 ${res_count}/${app_count} apps" "pass"
    else
        check "可自愈" "资源限制待设置 (${res_count}/${app_count})" "warn"
    fi
fi

# ─── Summary ───
echo ""
echo "══════════════════════════════════════════════════════════"
echo -e "  ${GREEN}Pass: $pass_count${NC}  ${RED}Fail: $fail_count${NC}  ${YELLOW}Warn: $warn_count${NC}"
if [ "$fail_count" -eq 0 ]; then
    echo -e "  ${GREEN}🎉 六可验证通过${NC}"
else
    echo -e "  ${RED}❌ $fail_count 项阻塞 — 修复后重新验证${NC}"
fi
echo "══════════════════════════════════════════════════════════"

# 写入综合报告 (所有变量现在都已初始化)
python -c "
import json
from datetime import datetime
report = {
    'verified_at': datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%SZ'),
    'results': {
        'observability': 'pass' if ${health_score:-0} >= 60 else 'fail',
        'traceability': 'pass',
        'auditability': 'pass' if ${hardcoded:-0} == 0 else 'fail',
        'verifiability': 'pass' if ${php_errs:-0} == 0 else 'fail',
        'evolvability': 'pass' if ${module_count:-0} >= 8 else 'warn',
        'self_healing': 'pass' if '${has_rollback_script}' != '' else 'warn'
    },
    'summary': {'pass': ${pass_count:-0}, 'fail': ${fail_count:-0}, 'warn': ${warn_count:-0}},
    'pass': True if ${fail_count:-0} == 0 else False
}
json.dump(report, open('$REPORTS/devops-six-capability-report.json','w'), indent=2, default=str)
" 2>/dev/null || true

[ "$fail_count" -eq 0 ] && exit 0 || exit 1
