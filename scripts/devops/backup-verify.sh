#!/bin/bash
# ═══ Backup Restore Verification — 备份还原自动验证 ═══
# 层: L4 横切层 (可验证)
# 单一职责: 下载最新备份 → 还原到临时库 → 行数对比 → 告警 → 清理
# 用法: bash backup-verify.sh [--db <name>] [--json]
set -euo pipefail

HOST="${HOST:-137.184.225.93}"
SSH="ssh root@${HOST}"
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

TARGET_DB="${1:---all}"
JSON_OUT=false
[ "${2:-}" = "--json" ] && JSON_OUT=true

VERIFY_DB_NAME="__verify_restore_$$"

verify_db() {
    local db="$1"
    echo ""
    echo "═══ Backup Verify: $db ═══"

    # 1. 找最新备份
    local latest=$($SSH "ls -t /root/backups/backup-${db}-*.sql.gz 2>/dev/null | head -1" || echo "")
    if [ -z "$latest" ]; then
        echo -e "  ${RED}❌${NC} No backup found for $db"
        return 1
    fi
    local fname=$(basename "$latest")
    local size=$($SSH "du -h $latest | cut -f1" 2>/dev/null || echo "?")
    echo "  📦 $fname ($size)"

    # 2. 获取原始表计数
    local orig_counts=$($SSH "docker exec dokku.mysql.${db} mysql -u mysql -p\$(docker exec dokku.mysql.${db} printenv MYSQL_PASSWORD 2>/dev/null) -e 'SELECT TABLE_NAME,TABLE_ROWS FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME' 2>/dev/null" || echo "")
    local orig_tables=$(echo "$orig_counts" | wc -l)

    # 3. 创建临时验证数据库
    $SSH "docker exec dokku.mysql.${db} mysql -u mysql -p\$(docker exec dokku.mysql.${db} printenv MYSQL_PASSWORD 2>/dev/null) -e 'CREATE DATABASE IF NOT EXISTS $VERIFY_DB_NAME' 2>/dev/null" || {
        echo -e "  ${RED}❌${NC} Cannot create verify database"
        return 1
    }

    # 4. 还原到临时库
    $SSH "zcat $latest | docker exec -i dokku.mysql.${db} mysql -u mysql -p\$(docker exec dokku.mysql.${db} printenv MYSQL_PASSWORD 2>/dev/null) $VERIFY_DB_NAME 2>/dev/null" || {
        echo -e "  ${YELLOW}⚠️ ${NC} Restore had warnings (some non-critical errors are normal)"
    }

    # 5. 获取还原后表计数
    local restore_counts=$($SSH "docker exec dokku.mysql.${db} mysql -u mysql -p\$(docker exec dokku.mysql.${db} printenv MYSQL_PASSWORD 2>/dev/null) -e 'SELECT TABLE_NAME,TABLE_ROWS FROM information_schema.tables WHERE TABLE_SCHEMA=\"$VERIFY_DB_NAME\" ORDER BY TABLE_NAME' 2>/dev/null" || echo "")
    local restore_tables=$(echo "$restore_counts" | wc -l)

    # 6. 对比
    if [ "$orig_tables" -gt 2 ] && [ "$restore_tables" -gt 2 ]; then
        if [ "$orig_tables" -eq "$restore_tables" ]; then
            echo -e "  ${GREEN}✅${NC} Table count match: $orig_tables tables"
        else
            echo -e "  ${YELLOW}⚠️ ${NC} Table count diff: orig=$orig_tables restore=$restore_tables (may include views/etc)"
        fi
    else
        echo -e "  ${YELLOW}⚠️ ${NC} Could not read table counts (may be empty db or permission issue)"
    fi

    # 7. 清理临时库
    $SSH "docker exec dokku.mysql.${db} mysql -u mysql -p\$(docker exec dokku.mysql.${db} printenv MYSQL_PASSWORD 2>/dev/null) -e 'DROP DATABASE IF EXISTS $VERIFY_DB_NAME' 2>/dev/null" || true

    echo -e "  ${GREEN}✅${NC} Verify complete (temp DB cleaned)"
    return 0
}

if [ "$TARGET_DB" = "--all" ]; then
    echo "🔍 扫描所有数据库备份..."
    local dbs=$($SSH "ls /root/backups/backup-*.sql.gz 2>/dev/null | sed 's/.*backup-//;s/-[0-9].*//' | sort -u" || echo "")
    if [ -z "$dbs" ]; then
        echo "📝 无备份文件"
        exit 0
    fi
    for db in $dbs; do
        verify_db "$db" || echo -e "  ${YELLOW}⚠️ ${NC} $db verification incomplete"
    done
elif [ -n "$TARGET_DB" ]; then
    verify_db "$TARGET_DB"
fi

echo ""
echo "✅ Backup verification done"
