#!/bin/bash
# ═══ DevOps Object Discovery — 自动发现 10 种对象类型 ═══
# 层: L1.5 对象注册表层
# 单一职责: 发现对象 → 输出 JSON → registry.sh 消费
# 禁止: 本脚本不写 registry.json (由 registry.sh 负责)
#
# 用法:
#   bash discover.sh                    # 发现所有类型
#   bash discover.sh --type project     # 只发现 project
#   bash discover.sh --host 137.184.225.93  # 指定服务器

set -euo pipefail

HOST="${HOST:-137.184.225.93}"
SSH_USER="${SSH_USER:-root}"
SSH="ssh ${SSH_USER}@${HOST}"
PROJECTS_DIR="${PROJECTS_DIR:-/e/project}"
OUTPUT_DIR="${OUTPUT_DIR:-reports}"

mkdir -p "$OUTPUT_DIR"

# ─── 工具函数 ───

json_field() {
    local file="$1" field="$2"
    cat "$file" | python -c "import sys,json; d=json.load(sys.stdin); print(d.get('$field',''))" 2>/dev/null || echo ""
}

remote_exec() {
    $SSH "$@" 2>/dev/null || echo ""
}

# ─── Discover: host ───

discover_host() {
    local host_id="${HOST//./-}"
    cat <<EOF
{
  "id": "host:$host_id",
  "type": "host",
  "props": {
    "ip": "$HOST",
    "os": "$(remote_exec 'cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | cut -d= -f2' || echo 'unknown')",
    "dokku_version": "$(remote_exec 'dokku version 2>/dev/null' || echo 'unknown')",
    "cpu_count": "$(remote_exec 'nproc 2>/dev/null' || echo '0')",
    "memory_mb": "$(remote_exec 'free -m 2>/dev/null | grep Mem | awk "{print \$2}"' || echo '0')",
    "disk_gb": "$(remote_exec 'df -BG / 2>/dev/null | tail -1 | awk "{print \$2}"' || echo '0')"
  }
}
EOF
}

# ─── Discover: projects (本地扫描 .deploy.json) ───

discover_projects() {
    for d in "$PROJECTS_DIR"/*/; do
        local manifest="$d.deploy.json"
        [ -f "$manifest" ] || continue
        local name=$(json_field "$manifest" "name")
        [ -n "$name" ] || continue
        local type=$(json_field "$manifest" "type")
        local domain=$(json_field "$manifest" "domain")
        local deployed=$(json_field "$manifest" "deployed")
        local note=$(json_field "$manifest" "note")
        local db_name=$(json_field "$manifest" "database.name")
        local secret_count=0
        # Count secrets from manifest
        secret_count=$(cat "$manifest" | python -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('secrets',[])))" 2>/dev/null || echo "0")

        cat <<EOF
{
  "id": "project:$name",
  "type": "project",
  "status": "$([ "$deployed" = "true" ] && echo 'deployed' || echo 'pending')",
  "props": {
    "project_type": "$type",
    "domain": "$domain",
    "database": "$db_name",
    "secret_count": $secret_count,
    "note": "$note",
    "manifest_path": "$manifest"
  }
}
EOF
    done
}

# ─── Discover: apps (远程 Dokku) ───

discover_apps() {
    remote_exec "dokku apps:list 2>/dev/null" | tail -n +2 | while read -r app; do
        [ -z "$app" ] && continue
        local domain=$(remote_exec "dokku domains:report $app --dokku-domains-simple 2>/dev/null" | head -1 || echo "")
        local branch=$(remote_exec "dokku git:report $app 2>/dev/null" | grep "deploy-branch" | cut -d: -f2 | tr -d ' ' || echo "")

        cat <<EOF
{
  "id": "app:$app",
  "type": "app",
  "status": "active",
  "props": {
    "domain": "$domain",
    "deploy_branch": "$branch",
    "dokku_app": "$app"
  }
}
EOF
    done
}

# ─── Discover: databases (远程 Dokku MySQL) ───

discover_databases() {
    remote_exec "dokku mysql:list 2>/dev/null" | tail -n +2 | while read -r db; do
        [ -z "$db" ] && continue
        local linked=$(remote_exec "dokku mysql:info $db --links 2>/dev/null" | head -1 || echo "")

        cat <<EOF
{
  "id": "database:$db",
  "type": "database",
  "status": "active",
  "props": {
    "db_name": "$db",
    "linked_apps": "$linked",
    "engine": "mysql"
  }
}
EOF
    done
}

# ─── Discover: secrets (远程 Dokku config, 脱敏) ───

discover_secrets() {
    remote_exec "dokku apps:list 2>/dev/null" | tail -n +2 | while read -r app; do
        [ -z "$app" ] && continue
        remote_exec "dokku config:export $app --format shell 2>/dev/null" | while read -r line; do
            [ -z "$line" ] && continue
            local key=$(echo "$line" | cut -d= -f1)
            [ -z "$key" ] && continue
            # 跳过 Dokku 内部变量
            [[ "$key" == DOKKU_* ]] && continue
            [[ "$key" == GIT_* ]] && continue

            cat <<EOF
{
  "id": "secret:$app:$key",
  "type": "secret",
  "status": "active",
  "props": {
    "app": "$app",
    "key": "$key"
  }
}
EOF
        done
    done
}

# ─── Discover: deployments (远程 git report) ───

discover_deployments() {
    remote_exec "dokku apps:list 2>/dev/null" | tail -n +2 | while read -r app; do
        [ -z "$app" ] && continue
        local report=$(remote_exec "dokku git:report $app 2>/dev/null")
        local commit=$(echo "$report" | grep "Git deploy rev" | cut -d: -f2- | tr -d ' ' || echo "")
        local branch=$(echo "$report" | grep "Git deploy branch" | cut -d: -f2- | tr -d ' ' || echo "")
        local dir=$(echo "$report" | grep "Git rev env var dir" | cut -d: -f2- | tr -d ' ' || echo "")

        [ -z "$commit" ] && continue

        # 读取 commit 时间戳
        local ts=""
        if [ -n "$dir" ] && [ -f "${dir}/SOURCETAG" ] 2>/dev/null; then
            ts=$(remote_exec "stat -c '%Y' ${dir}/SOURCETAG 2>/dev/null" || echo "")
        fi

        cat <<EOF
{
  "id": "deployment:$app:$commit",
  "type": "deployment",
  "status": "active",
  "props": {
    "app": "$app",
    "commit": "$commit",
    "branch": "$branch",
    "timestamp": "$ts"
  }
}
EOF
    done
}

# ─── Discover: backups (远程 /root/backups/) ───

discover_backups() {
    remote_exec "ls -la /root/backups/ 2>/dev/null" | grep '\.sql\.gz$' | while read -r line; do
        local fname=$(echo "$line" | awk '{print $NF}')
        local size=$(echo "$line" | awk '{print $5}')
        local ts=$(echo "$line" | awk '{print $6, $7, $8}')

        cat <<EOF
{
  "id": "backup:$fname",
  "type": "backup",
  "status": "active",
  "props": {
    "filename": "$fname",
    "size_bytes": "$size",
    "created_at": "$ts",
    "path": "/root/backups/$fname"
  }
}
EOF
    done
}

# ─── Discover: healthchecks (从 registry 中的 project/app) ───

discover_healthchecks() {
    # 从项目 manifest 读取 health 端点
    for d in "$PROJECTS_DIR"/*/; do
        local manifest="$d.deploy.json"
        [ -f "$manifest" ] || continue
        local name=$(json_field "$manifest" "name")
        local domain=$(json_field "$manifest" "domain")
        [ -z "$name" ] || [ -z "$domain" ] && continue

        # 读 services[].health
        cat "$manifest" | python -c "
import sys, json
d = json.load(sys.stdin)
for s in d.get('services', []):
    h = s.get('health', '/')
    print(json.dumps({'app': d['name'], 'domain': d['domain'], 'path': h}))
" 2>/dev/null | while read -r hc; do
            [ -z "$hc" ] && continue
            local path=$(echo "$hc" | python -c "import sys,json; print(json.load(sys.stdin).get('path','/'))" 2>/dev/null || echo "/")

            cat <<EOF
{
  "id": "healthcheck:$name",
  "type": "healthcheck",
  "status": "active",
  "props": {
    "app": "$name",
    "url": "https://$domain$path",
    "expected_status": 200,
    "interval_sec": 30
  }
}
EOF
        done
    done
}

# ─── Discover: resourcelimits (远程 Dokku resource:report) ───

discover_resourcelimits() {
    remote_exec "dokku apps:list 2>/dev/null" | tail -n +2 | while read -r app; do
        [ -z "$app" ] && continue
        local report=$(remote_exec "dokku resource:report $app 2>/dev/null")
        local mem=$(echo "$report" | grep "Memory limit" | cut -d: -f2- | tr -d ' ' || echo "unlimited")
        local cpu=$(echo "$report" | grep "CPU limit" | cut -d: -f2- | tr -d ' ' || echo "unlimited")

        cat <<EOF
{
  "id": "resourcelimit:$app",
  "type": "resourcelimit",
  "status": "$([ "$mem" = "unlimited" ] && echo 'unset' || echo 'active')",
  "props": {
    "app": "$app",
    "memory_limit": "$mem",
    "cpu_limit": "$cpu"
  }
}
EOF
    done
}

# ─── Discover: alerts (从配置文件) ───

discover_alerts() {
    local alert_file="${ALERT_FILE:-config/devops-alerts.json}"
    [ -f "$alert_file" ] || return 0

    cat "$alert_file" | python -c "
import sys, json
alerts = json.load(sys.stdin)
for a in alerts:
    print(json.dumps(a))
" 2>/dev/null | while read -r alert; do
        local id=$(echo "$alert" | python -c "import sys,json; print(json.load(sys.stdin).get('id',''))" 2>/dev/null || echo "")
        local alert_type=$(echo "$alert" | python -c "import sys,json; print(json.load(sys.stdin).get('type',''))" 2>/dev/null || echo "")

        cat <<EOF
{
  "id": "alert:$id",
  "type": "alert",
  "status": "active",
  "props": $alert
}
EOF
    done
}

# ─── Main ───

main() {
    local filter_type="${1:-all}"

    echo "["
    local first=true

    discover_and_output() {
        local type="$1"
        local func="$2"
        if [ "$filter_type" = "all" ] || [ "$filter_type" = "$type" ]; then
            local results=$($func)
            if [ -n "$results" ]; then
                echo "$results" | while read -r obj; do
                    [ -z "$obj" ] && continue
                    [ "$first" = "true" ] && first=false || echo ","
                    echo "$obj"
                done
            fi
        fi
    }

    discover_and_output "host" discover_host
    discover_and_output "project" discover_projects
    discover_and_output "app" discover_apps
    discover_and_output "database" discover_databases
    discover_and_output "secret" discover_secrets
    discover_and_output "deployment" discover_deployments
    discover_and_output "backup" discover_backups
    discover_and_output "healthcheck" discover_healthchecks
    discover_and_output "resourcelimit" discover_resourcelimits
    discover_and_output "alert" discover_alerts

    echo "]"
}

# 执行
if [ "${1:-}" = "--type" ]; then
    main "${2:-all}" > "$OUTPUT_DIR/devops-discovered.json"
    echo "✅ Discovery complete → $OUTPUT_DIR/devops-discovered.json"
else
    main "${1:-all}"
fi
