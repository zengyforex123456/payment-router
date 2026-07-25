#!/bin/sh
set -e
echo "PaymentRouter starting..."
echo "DB: ${DB_HOST}:${DB_PORT}/${DB_NAME} as ${DB_USER}"

# Wait for MySQL
echo "Waiting for MySQL..."
until mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASSWORD}" --ssl-mode=DISABLED -e "SELECT 1" 2>/dev/null; do
    sleep 1
done
echo "MySQL ready"

# Run migrations
echo "Running migrations..."
for f in /var/www/database/migrations/*payment_router*.sql; do
    [ -f "$f" ] || continue
    echo "  $(basename $f)"
    mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASSWORD}" --ssl-mode=DISABLED "${DB_NAME}" < "$f" 2>/dev/null || true
done
echo "Migrations done"

# Start
echo "API on 0.0.0.0:8080"
exec php -S 0.0.0.0:8080 -t /var/www /var/www/docker/payment-router/index.php

