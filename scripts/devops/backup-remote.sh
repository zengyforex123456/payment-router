#!/bin/bash
# ═══ 远程备份 — S3/SCP 异地存储 ═══
# 用法: bash backup-remote.sh [--s3] [--scp user@host:/path]
# 环境变量:
#   S3_ENDPOINT / S3_BUCKET / S3_ACCESS_KEY / S3_SECRET_KEY
#   SCP_TARGET=user@backup-host:/backups/

set -euo pipefail
HOST="${HOST:-137.184.225.93}"
SSH="ssh root@${HOST}"

MODE="${1:-scp}"
echo "📦 Remote backup sync..."

# ─── 找到最新本地备份 ───
LATEST=$($SSH "ls -t /root/backups/*.sql.gz 2>/dev/null | head -5" || echo "")
[ -z "$LATEST" ] && { echo "No backups found"; exit 0; }

for f in $LATEST; do
    fname=$(basename "$f")
    echo "  $fname"

    case "$MODE" in
        --scp)
            TARGET="${SCP_TARGET:-}"
            [ -z "$TARGET" ] && { echo "Set SCP_TARGET=user@host:/path"; exit 1; }
            $SSH "scp $f $TARGET/" 2>/dev/null && echo "    ✅ SCP" || echo "    ❌ SCP failed"
            ;;
        --s3)
            [ -z "${S3_ENDPOINT:-}" ] && { echo "Set S3_ENDPOINT/S3_BUCKET/S3_ACCESS_KEY/S3_SECRET_KEY"; exit 1; }
            $SSH "curl -s -X PUT -T $f \\
              -H 'X-Auth-Key: ${S3_ACCESS_KEY}' \\
              '${S3_ENDPOINT}/${S3_BUCKET}/$fname'" 2>/dev/null && echo "    ✅ S3" || echo "    ❌ S3 failed"
            ;;
        *)
            echo "用法: bash backup-remote.sh --scp | --s3"
            ;;
    esac
done

echo "✅ Remote sync done"
