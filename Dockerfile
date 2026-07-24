# ═══ Converge Dockerfile v4 — Multi-Stage ═══
# Stage 1 (builder): 完整源码 + composer install
# Stage 2 (runtime): 仅运行时文件 + 非 root + 无 shell 工具
#
# 用法: docker build -t converge .

# ── Stage 1: Builder ──
FROM php:8.3-fpm AS builder

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl ca-certificates unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions mysqli pdo_mysql mbstring zip redis bcmath maxminddb

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/converge
COPY . /var/www/converge/

# 生产依赖安装
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-progress 2>&1

# 构建门禁
COPY bin/verify-deps.php /tmp/verify-deps.php
RUN php /tmp/verify-deps.php && rm /tmp/verify-deps.php

# ── Stage 2: Runtime ──
FROM php:8.3-fpm

# 仅安装运行时系统包 (不含 build 工具)
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx supervisor default-mysql-client \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get purge -y --autoremove curl unzip 2>/dev/null || true

# 安装 PHP 扩展 (运行时)
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions mysqli pdo_mysql mbstring zip redis bcmath \
    && rm /usr/local/bin/install-php-extensions

# 配置 (不拷贝源码)
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/99-converge.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisor.conf /etc/supervisor/conf.d/app.conf

# ── 从 Builder 复制运行时文件 ──
WORKDIR /var/www/converge

# vendor/ — 第三方依赖
COPY --from=builder /var/www/converge/vendor/ /var/www/converge/vendor/

# 运行时源码 (不含 dev/test/CI)
COPY --from=builder /var/www/converge/public/ /var/www/converge/public/
COPY --from=builder /var/www/converge/modules/ /var/www/converge/modules/
COPY --from=builder /var/www/converge/app/ /var/www/converge/app/
COPY --from=builder /var/www/converge/templates/ /var/www/converge/templates/
COPY --from=builder /var/www/converge/config/ /var/www/converge/config/
COPY --from=builder /var/www/converge/database/ /var/www/converge/database/
COPY --from=builder /var/www/converge/resources/ /var/www/converge/resources/

# 运行时工具脚本 (迁移 + 快照)
COPY --from=builder /var/www/converge/scripts/run-migrations.php /var/www/converge/scripts/run-migrations.php
COPY --from=builder /var/www/converge/scripts/generate-static-snapshot.php /var/www/converge/scripts/generate-static-snapshot.php
COPY --from=builder /var/www/converge/bin/ /var/www/converge/bin/

# 前端资源
RUN mkdir -p /var/www/converge/public/build/css /var/www/converge/public/build/js \
    && cp -r /var/www/converge/resources/css/*.css /var/www/converge/public/build/css/ 2>/dev/null || true \
    && cp -r /var/www/converge/resources/js/*.js /var/www/converge/public/build/js/ 2>/dev/null || true \
    && cp -r /var/www/converge/resources/js/components /var/www/converge/public/build/js/ 2>/dev/null || true \
    && cp -r /var/www/converge/resources/js/stores /var/www/converge/public/build/js/ 2>/dev/null || true \
    && cp -r /var/www/converge/resources/js/utils /var/www/converge/public/build/js/ 2>/dev/null || true

# 权限: 非 root 运行
RUN mkdir -p /var/www/converge/storage/cache/latte /var/www/converge/storage/logs /var/www/converge/storage/snapshots \
    && chown -R www-data:www-data /var/www/converge \
    && chmod -R 755 /var/www/converge/storage

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Security: nginx runs workers as www-data (configured in nginx.conf)
# PHP-FPM runs as www-data (configured in php-fpm-prod.conf)
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
