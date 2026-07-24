#!/bin/bash
# deploy-verify.sh — 原子化部署+验证 (解决 scp 静默失败 + OPcache 旧内容)
#
# 用法:
#   bash scripts/deploy-verify.sh file1.php file2.php ...    # 部署指定文件
#   bash scripts/deploy-verify.sh --all                       # 部署全部变更
#   bash scripts/deploy-verify.sh --verify                    # 仅验证当前部署
#
# 每步原子化: 失败→停止→报告→不继续
# 退出码: 0=全部通过  1=部署失败  2=验证失败

set -euo pipefail

# ═══ 配置 ═══
SERVER="${DEPLOY_SERVER:-root@137.184.225.93}"
APP_DIR="${DEPLOY_PATH:-/var/www/converge}"
LOCAL_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PASS=0; FAIL=0

# ═══ 颜色 ═══
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
ok()   { echo -e "  [${GREEN}PASS${NC}] $1"; ((PASS++)); }
fail() { echo -e "  [${RED}FAIL${NC}] $1"; ((FAIL++)); }
warn() { echo -e "  [${YELLOW}WARN${NC}] $1"; }

# ═══ Step 1: 本地校验 ═══
echo "═══ Step 1: Local Syntax Check ═══"
for f in "$@"; do
    [[ "$f" == "--"* ]] && continue
    [[ ! -f "$LOCAL_DIR/$f" ]] && { fail "Missing: $f"; continue; }
    if [[ "$f" == *.php ]]; then
        php -l "$LOCAL_DIR/$f" > /dev/null 2>&1 && ok "PHP syntax: $f" || fail "PHP syntax: $f"
    else
        ok "File exists: $f"
    fi
done
[[ $FAIL -gt 0 ]] && { echo "❌ Local checks failed"; exit 1; }

# ═══ Step 2: 服务器备份 ═══
echo ""
echo "═══ Step 2: Server Backup ═══"
BACKUP_DIR="$APP_DIR/.deploy-backups/$(date +%Y%m%d-%H%M%S)"
ssh "$SERVER" "mkdir -p $BACKUP_DIR" 2>&1 || { echo "❌ SSH failed"; exit 1; }
for f in "$@"; do
    [[ "$f" == "--"* ]] && continue
    ssh "$SERVER" "cp --parents $APP_DIR/$f $BACKUP_DIR/ 2>/dev/null" && ok "Backup: $f" || warn "No backup: $f (new file?)"
done

# ═══ Step 3: 原子写入 (rm + recreate) ═══
echo ""
echo "═══ Step 3: Atomic Deploy ═══"
for f in "$@"; do
    [[ "$f" == "--"* ]] && continue
    local_md5=$(md5sum "$LOCAL_DIR/$f" | awk '{print $1}')

    # Atomic: remove old → write new → chown → verify
    ssh "$SERVER" "
        rm -f $APP_DIR/$f && \
        cat > $APP_DIR/$f && \
        chown www-data:www-data $APP_DIR/$f && \
        chmod 644 $APP_DIR/$f && \
        echo \"WRITTEN\"
    " < "$LOCAL_DIR/$f" > /dev/null 2>&1

    # Verify: server checksum matches local
    server_md5=$(ssh "$SERVER" "md5sum $APP_DIR/$f" | awk '{print $1}')

    if [[ "$local_md5" == "$server_md5" ]]; then
        ok "Deploy: $f ($local_md5)"
    else
        fail "Deploy: $f (local=$local_md5 server=$server_md5)"
        echo "  → Rolling back..."
        ssh "$SERVER" "cp $BACKUP_DIR/$APP_DIR/$f $APP_DIR/$f" 2>/dev/null && echo "  → Rollback OK" || echo "  → No backup to rollback"
    fi
done
[[ $FAIL -gt 0 ]] && { echo "❌ Deploy failed — check FAIL items above"; exit 1; }

# ═══ Step 4: OPcache 清除 ═══
echo ""
echo "═══ Step 4: Clear OPcache ═══"
ssh "$SERVER" "
    systemctl stop php8.3-fpm && sleep 1 && systemctl start php8.3-fpm && echo 'OPCACHE_CLEARED'
" 2>&1 > /dev/null && ok "OPcache cleared" || fail "OPcache clear"

# ═══ Step 5: HTTP 验证 ═══
echo ""
echo "═══ Step 5: HTTP Verification ═══"
for f in "$@"; do
    [[ "$f" == "--"* ]] && continue
    url_path="${f#public/}"
    http_code=$(ssh "$SERVER" "curl -s -o /dev/null -w '%{http_code}' http://localhost/$url_path 2>/dev/null")
    if [[ "$http_code" == "200" || "$http_code" == "302" ]]; then
        ok "HTTP: $url_path → $http_code"
    else
        fail "HTTP: $url_path → $http_code (expected 200/302)"
    fi
done

# ═══ Summary ═══
echo ""
echo "═══════════════════════════════════════"
echo "  Deploy: $PASS passed, $FAIL failed"
echo "  Server: $SERVER"
echo "  Backup: $BACKUP_DIR"
echo "═══════════════════════════════════════"
[[ $FAIL -gt 0 ]] && exit 1
exit 0
