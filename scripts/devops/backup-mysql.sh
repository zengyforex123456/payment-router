#!/bin/bash
# ═══ MySQL 自动备份 — 每日凌晨 2:00 (cron) ═══
# 方式: docker exec → 容器内 mysqldump → 输出到宿主机
# 保留 7 天，自动清理

BACKUP_DIR="/root/backups"
RETENTION_DAYS=7
DATE=$(date +%Y%m%d_%H%M)
LOG_FILE="$BACKUP_DIR/backup.log"

mkdir -p "$BACKUP_DIR"

echo "[$(date)] Starting backup..." >> "$LOG_FILE"

for db in $(dokku mysql:list 2>/dev/null | tail -n +2); do
    [ -z "$db" ] && continue

    CONTAINER="dokku.mysql.${db}"
    BACKUP_FILE="$BACKUP_DIR/backup-${db}-${DATE}.sql.gz"

    # 检查容器运行中
    if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
        echo "  FAIL: $db (container not running)" >> "$LOG_FILE"
        continue
    fi

    # 在容器内执行 mysqldump (密码从容器环境变量读取，不暴露到宿主机)
    if docker exec "$CONTAINER" sh -c \
        "mysqldump --all-databases --single-transaction --routines --triggers --no-tablespaces -u root -p\$MYSQL_ROOT_PASSWORD" \
        2>> "$LOG_FILE" | gzip > "$BACKUP_FILE" 2>> "$LOG_FILE"; then
        SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
        echo "  OK: $db → $(basename $BACKUP_FILE) ($SIZE)" >> "$LOG_FILE"
    else
        echo "  FAIL: $db (mysqldump error)" >> "$LOG_FILE"
        rm -f "$BACKUP_FILE"
    fi
done

# 清理 >7 天备份
DELETED=$(find "$BACKUP_DIR" -name "backup-*.sql.gz" -mtime +$RETENTION_DAYS -delete -print 2>/dev/null | wc -l)
echo "  Cleaned: $DELETED old backups" >> "$LOG_FILE"
echo "[$(date)] Backup complete" >> "$LOG_FILE"
