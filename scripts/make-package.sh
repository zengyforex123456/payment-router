#!/bin/bash
# ═══ PaymentRouter — Source Package Builder ═══
# 专业版源码打包：生成可分发的 zip 文件。
# 用法: bash scripts/make-package.sh [version]
set -euo pipefail

VERSION="${1:-0.1.0}"
PACKAGE="payment-router-v${VERSION}"
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="/tmp/${PACKAGE}"

echo "══════════════════════════════════════════"
echo "  PaymentRouter — Package Builder v${VERSION}"
echo "══════════════════════════════════════════"

# Clean
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

# Copy core files
echo "📦 Copying core module..."
cp -r "$PROJECT_ROOT/modules/PaymentRouter" "$BUILD_DIR/modules/PaymentRouter"

# Copy migrations
echo "📦 Copying migrations..."
mkdir -p "$BUILD_DIR/database/migrations"
cp "$PROJECT_ROOT/database/migrations/"*payment_router*.sql "$BUILD_DIR/database/migrations/" 2>/dev/null || true

# Copy Docker + standalone server
echo "📦 Copying Docker files..."
mkdir -p "$BUILD_DIR/docker/payment-router"
cp -r "$PROJECT_ROOT/docker/payment-router/"* "$BUILD_DIR/docker/payment-router/"

# Copy install script
echo "📦 Copying install script..."
cp "$PROJECT_ROOT/scripts/install.sh" "$BUILD_DIR/install.sh"
chmod +x "$BUILD_DIR/install.sh"

# Copy composer.json (payment-router only)
echo "📦 Generating composer.json..."
cat > "$BUILD_DIR/composer.json" <<COMF
{
    "name": "converge/payment-router",
    "description": "AB Payment Router — Multi-site payment orchestration",
    "version": "${VERSION}",
    "type": "project",
    "require": {
        "php": ">=8.0",
        "ext-mysqli": "*",
        "ext-json": "*",
        "ext-mbstring": "*",
        "ext-openssl": "*",
        "ext-curl": "*"
    },
    "autoload": {
        "psr-4": {
            "Converge\\\\Modules\\\\PaymentRouter\\\\": "modules/PaymentRouter/"
        }
    }
}
COMF

# Copy README
cat > "$BUILD_DIR/README.md" <<README
# PaymentRouter v${VERSION} — AB 轮询支付中控

## 快速开始

\`\`\`bash
bash install.sh
\`\`\`

或使用 Docker:

\`\`\`bash
docker compose -f docker-compose.payment-router.yml up -d
\`\`\`

## 要求

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- curl, openssl

## 许可

专有许可。购买获得源码使用权。
README

# Copy docker-compose
cp "$PROJECT_ROOT/docker-compose.payment-router.yml" "$BUILD_DIR/docker-compose.yml"

# Remove test/dev files
echo "🧹 Cleaning dev files..."
find "$BUILD_DIR" -name '*.test.php' -delete 2>/dev/null || true
find "$BUILD_DIR" -name '.gitkeep' -delete 2>/dev/null || true

# Create zip
echo "📦 Creating package..."
cd /tmp
zip -r "${PACKAGE}.zip" "$PACKAGE" -x "*.git*" >/dev/null
mv "${PACKAGE}.zip" "$PROJECT_ROOT/"

# Cleanup
rm -rf "$BUILD_DIR"

# Show result
SIZE=$(du -h "$PROJECT_ROOT/${PACKAGE}.zip" | cut -f1)
echo ""
echo "══════════════════════════════════════════"
echo "  ✅ Package created: ${PACKAGE}.zip (${SIZE})"
echo "══════════════════════════════════════════"
echo ""
echo "Contents:"
unzip -l "$PROJECT_ROOT/${PACKAGE}.zip" | tail -20
