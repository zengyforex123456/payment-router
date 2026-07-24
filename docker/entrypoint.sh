#!/bin/sh
# ═══ Converge Docker Entrypoint ═══
# 1. 等待 MySQL 就绪
# 2. 运行数据库迁移 (幂等)
# 3. 生成首轮快照
# 4. 启动 Supervisor (nginx + php-fpm)
set -e

echo "═══ Converge Entrypoint ═══"

# 清理 Latte 锁文件残留
rm -f /var/www/converge/storage/cache/latte/*.lock 2>/dev/null

# 等待 MySQL
if [ -n "$DB_HOST" ]; then
    echo "⏳ Waiting for MySQL at $DB_HOST..."
    for i in $(seq 1 30); do
        if php -r "new mysqli('$DB_HOST', '${DB_USER:-root}', '${DB_PASSWORD:-}', '${DB_NAME:-converge}');" 2>/dev/null; then
            echo "✅ MySQL ready (attempt $i)"
            break
        fi
        [ "$i" -eq 30 ] && echo "❌ MySQL timeout" && exit 1
        sleep 2
    done

    # 运行迁移 (幂等 — 已执行的跳过)
    echo "📦 Running migrations..."
    php /var/www/converge/scripts/run-migrations.php || {
        echo "❌ Migration failed"
        exit 1
    }
    echo "✅ Migrations done"
fi

# 生成快照
if [ -n "$DB_HOST" ]; then
    echo "📸 Generating static snapshot..."
    php /var/www/converge/scripts/generate-static-snapshot.php 2>/dev/null || echo "  (snapshot deferred)"
fi

echo "🚀 Starting services..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/app.conf
