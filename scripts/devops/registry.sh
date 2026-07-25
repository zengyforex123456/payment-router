#!/bin/bash
# ═══ DevOps Object Registry CLI — 统一管理所有 DevOps 对象 ═══
# 层: L1.5 对象注册表层
# 单一职责: 对象注册表 CRUD + 查询 + 健康检查
# 依赖: discover.sh (对象发现), registry.json (持久化)
#
# 用法:
#   bash registry.sh refresh              # 全量刷新注册表
#   bash registry.sh list                 # 列出所有对象
#   bash registry.sh list --type app      # 按类型过滤
#   bash registry.sh get app:converge     # 查看单个对象
#   bash registry.sh health               # 所有对象健康状态
#   bash registry.sh diff                 # 上次刷新以来的变更

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
REGISTRY_FILE="$PROJECT_ROOT/reports/devops-registry.json"
DISCOVERED_FILE="$PROJECT_ROOT/reports/devops-discovered.json"
DIFF_FILE="$PROJECT_ROOT/reports/devops-registry-diff.json"

mkdir -p "$PROJECT_ROOT/reports"

# ─── 工具: JSON 辅助 ───

_json_get()      { cat "$1" | python -c "import sys,json; print(json.load(sys.stdin)$2)" 2>/dev/null || echo ""; }
_json_pretty()   { cat "$1" | python -c "import sys,json; print(json.dumps(json.load(sys.stdin),indent=2,ensure_ascii=False))" 2>/dev/null; }

# ─── refresh: 全量刷新注册表 ───

cmd_refresh() {
    echo "🔍 发现 DevOps 对象..."

    # Step 1: 运行发现
    bash "$SCRIPT_DIR/discover.sh" --type all > /dev/null

    if [ ! -f "$DISCOVERED_FILE" ]; then
        echo "❌ 发现失败: $DISCOVERED_FILE 不存在"
        exit 1
    fi

    # Step 2: 生成 registry.json
    local now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    local host="${HOST:-137.184.225.93}"

    # Step 3: Diff (如果旧 registry 存在)
    if [ -f "$REGISTRY_FILE" ]; then
        python -c "
import json, sys

old = json.load(open('$REGISTRY_FILE'))
new_objects = json.load(open('$DISCOVERED_FILE'))
old_objects = old.get('objects', {})

# 构建新对象 map
new_map = {}
for obj in new_objects:
    new_map[obj['id']] = obj

old_map = {}
for k, v in old_objects.items():
    old_map[k] = v

diff = {'created': [], 'updated': [], 'removed': [], 'unchanged': 0}

for oid, obj in new_map.items():
    if oid not in old_map:
        diff['created'].append({'id': oid, 'type': obj['type']})
    elif json.dumps(obj.get('props',{}), sort_keys=True) != json.dumps(old_map[oid].get('props',{}), sort_keys=True):
        diff['updated'].append({'id': oid, 'type': obj['type'], 'old_props': old_map[oid].get('props',{}), 'new_props': obj.get('props',{})})
    else:
        diff['unchanged'] += 1

for oid in old_map:
    if oid not in new_map:
        diff['removed'].append({'id': oid, 'type': old_map[oid]['type']})

# 写入 diff
json.dump(diff, open('$DIFF_FILE','w'), indent=2, ensure_ascii=False, default=str)

# 打印摘要
created = diff['created']
updated = diff['updated']
removed = diff['removed']
unchanged = diff['unchanged']
print(f'NEW: {len(created)} | CHANGED: {len(updated)} | GONE: {len(removed)} | SAME: {unchanged}')
if created:
    for c in created: print(f'  + {c[\"type\"]}:{c[\"id\"]}')
if updated:
    for u in updated: print(f'  ~ {u[\"type\"]}:{u[\"id\"]}')
if removed:
    for r in removed: print(f'  - {r[\"type\"]}:{r[\"id\"]}')
" 2>/dev/null
    else
        echo "📝 首次注册 (无旧 registry)"
    fi

    # Step 4: 构建完整 registry
    python -c "
import json, sys

objects = json.load(open('$DISCOVERED_FILE'))

# 构建 map
obj_map = {}
type_count = {}
health_count = {'ok': 0, 'gone': 0, 'error': 0}

for obj in objects:
    oid = obj['id']
    otype = obj['type']
    obj['discovered_at'] = '$now'
    if 'health' not in obj:
        obj['health'] = 'ok'
    obj_map[oid] = obj

    type_count[otype] = type_count.get(otype, 0) + 1
    health_count[obj.get('health', 'ok')] = health_count.get(obj.get('health', 'ok'), 0) + 1

# 保留旧注册表中 gone 的对象
if __import__('os').path.exists('$REGISTRY_FILE'):
    old = json.load(open('$REGISTRY_FILE'))
    for oid, obj in old.get('objects', {}).items():
        if obj.get('health') == 'gone' and oid not in obj_map:
            obj_map[oid] = obj
            health_count['gone'] += 1

registry = {
    'version': '1.0',
    'host': '$host',
    'refreshed_at': '$now',
    'objects': obj_map,
    'summary': {
        'total': len(obj_map),
        'by_type': type_count,
        'by_health': health_count
    }
}

json.dump(registry, open('$REGISTRY_FILE','w'), indent=2, ensure_ascii=False, default=str)
print(f'✅ Registry: {len(obj_map)} objects ({len(type_count)} types)')
" 2>/dev/null

    echo "✅ 刷新完成 → $REGISTRY_FILE"
}

# ─── list: 列出对象 ───

cmd_list() {
    if [ ! -f "$REGISTRY_FILE" ]; then
        echo "❌ Registry 不存在，先运行: bash registry.sh refresh"
        exit 1
    fi

    local filter_type="${2:-all}"

    python -c "
import json, sys

reg = json.load(open('$REGISTRY_FILE'))
objects = reg.get('objects', {})

# 分隔线
print('=' * 72)
print(f\"{'ID':<40} {'TYPE':<14} {'HEALTH':<8} {'STATUS'}\")
print('-' * 72)

count = 0
for oid, obj in sorted(objects.items()):
    otype = obj.get('type', '?')
    if '$filter_type' != 'all' and otype != '$filter_type':
        continue
    health = obj.get('health', '?')
    status = obj.get('status', '?')
    print(f'{oid:<40} {otype:<14} {health:<8} {status}')
    count += 1

print('-' * 72)
print(f'Total: {count} (filter: $filter_type)')
print()
# Summary
s = reg.get('summary', {})
print('By Type:')
for t, c in sorted(s.get('by_type', {}).items()):
    print(f'  {t}: {c}')
print('By Health:')
for h, c in sorted(s.get('by_health', {}).items()):
    print(f'  {h}: {c}')
" 2>/dev/null
}

# ─── get: 查看单个对象 ───

cmd_get() {
    local oid="$2"
    [ -z "$oid" ] && { echo "用法: registry.sh get <object-id>"; exit 1; }

    if [ ! -f "$REGISTRY_FILE" ]; then
        echo "❌ Registry 不存在"
        exit 1
    fi

    python -c "
import json
reg = json.load(open('$REGISTRY_FILE'))
obj = reg.get('objects', {}).get('$oid')
if obj:
    print(json.dumps(obj, indent=2, ensure_ascii=False, default=str))
else:
    print(f'❌ Object not found: $oid')
    print(f'   Try: bash registry.sh list')
" 2>/dev/null
}

# ─── health: 健康状态面板 ───

cmd_health() {
    if [ ! -f "$REGISTRY_FILE" ]; then
        echo "❌ Registry 不存在"
        exit 1
    fi

    python -c "
import json
reg = json.load(open('$REGISTRY_FILE'))

print('╔══════════════════════════════════════════════════════════════╗')
print('║           DevOps Object Health Dashboard                     ║')
print('╠══════════════════════════════════════════════════════════════╣')

issues = 0
for oid, obj in sorted(reg.get('objects', {}).items()):
    health = obj.get('health', '?')
    symbol = '✅' if health == 'ok' else ('⚠️' if health == 'gone' else '❌')
    if health != 'ok':
        issues += 1
    print(f'  {symbol} {oid:<45} {health}')

print('╠══════════════════════════════════════════════════════════════╣')
total = reg['summary']['total']
ok_count = reg['summary']['by_health'].get('ok', 0)
print(f'  Total: {total} | OK: {ok_count} | Issues: {issues}')
if issues > 0:
    print(f'  ⚠️  {issues} objects need attention')
else:
    print(f'  🎉 All objects healthy')
print('╚══════════════════════════════════════════════════════════════╝')
" 2>/dev/null
}

# ─── diff: 查看变更 ───

cmd_diff() {
    if [ ! -f "$DIFF_FILE" ]; then
        echo "📝 无 diff 数据 (先运行: bash registry.sh refresh)"
        exit 0
    fi
    _json_pretty "$DIFF_FILE"
}

# ─── Main ───

case "${1:-}" in
    refresh) cmd_refresh "$@" ;;
    list)    cmd_list "$@" ;;
    get)     cmd_get "$@" ;;
    health)  cmd_health ;;
    diff)    cmd_diff ;;
    *)
        echo "DevOps Object Registry CLI"
        echo ""
        echo "用法: bash registry.sh <command> [options]"
        echo ""
        echo "Commands:"
        echo "  refresh              全量刷新注册表 (发现→diff→注册)"
        echo "  list                 列出所有对象"
        echo "  list --type app      按类型过滤"
        echo "  get <object-id>      查看单个对象详情"
        echo "  health               健康状态面板"
        echo "  diff                 查看上次刷新以来的变更"
        echo ""
        echo "Examples:"
        echo "  bash registry.sh refresh"
        echo "  bash registry.sh list --type project"
        echo "  bash registry.sh get app:payment-router"
        echo "  bash registry.sh health"
        ;;
esac
