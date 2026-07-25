#!/bin/sh
echo "PaymentRouter starting on port ${PORT:-5000}..."

# Start PHP immediately (Dokku handles MySQL via DATABASE_URL)
exec php -S 0.0.0.0:${PORT:-5000} -t /var/www /var/www/docker/payment-router/index.php

