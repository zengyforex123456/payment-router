FROM php:8.2-cli-alpine

RUN apk add --no-cache mysql-client curl \
    && docker-php-ext-install mysqli pdo pdo_mysql mbstring 2>/dev/null \
    && docker-php-ext-enable mysqli 2>/dev/null || true

RUN addgroup -g 1000 app && adduser -u 1000 -G app -D app

WORKDIR /var/www
COPY . /var/www/

RUN chown -R app:app /var/www
USER app

EXPOSE 8080

COPY docker/payment-router/entrypoint.sh /entrypoint.sh
USER root
RUN chmod +x /entrypoint.sh
USER app

ENTRYPOINT ["/entrypoint.sh"]
# cache bust 1784951332
