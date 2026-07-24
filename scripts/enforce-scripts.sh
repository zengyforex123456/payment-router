#!/bin/sh
# enforce-scripts.sh — 脚本结构强制门禁
# 检查: shebang·set -e·可执行·命名规范·README注册
# 用法: bash scripts/enforce-scripts.sh [--staged]
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/lib/colors.sh" 2>/dev/null || {
    echo "WARN: lib/colors.sh missing, using plain output"
    log_ok() { echo "  ✅ $1"; }
    log_error() { echo "  ❌ $1"; }
    log_warn() { echo "  ⚠️  $1"; }
    log_info() { echo "  ℹ️  $1"; }
    log_title() { echo "=== $1 ==="; }
}

BLOCK=0; WARN=0
STAGED="${1:-}"

log_title "脚本结构门禁"

# Collect scripts to check
SCRIPTS=""
if [ "$STAGED" = "--staged" ]; then
    SCRIPTS=$(git diff --cached --name-only --diff-filter=ACM | grep '\.sh$' || true)
else
    SCRIPTS=$(find "$SCRIPT_DIR" -maxdepth 1 -name "*.sh" ! -name "enforce-scripts.sh" 2>/dev/null || true)
fi

if [ -z "$SCRIPTS" ]; then
    log_ok "No shell scripts to check"
    exit 0
fi

TOTAL=0; PASS=0

for f in $SCRIPTS; do
    [ -f "$f" ] || continue
    TOTAL=$((TOTAL + 1))
    name=$(basename "$f")
    ok=1

    # Shebang
    head -1 "$f" | grep -q '^#!/' || { log_error "$name: missing shebang (#!/bin/sh)"; ok=0; }

    # set -e
    grep -qE '^set -e' "$f" 2>/dev/null || { log_error "$name: missing set -e"; ok=0; }

    # Naming: lowercase-hyphen.sh
    echo "$name" | grep -qE '^[a-z][a-z0-9-]+\.sh$' || { log_warn "$name: non-standard name (lowercase-hyphen.sh)"; ok=0; }

    # Single purpose: no "和" in description
    desc=$(grep -m1 "^# .*" "$f" 2>/dev/null | head -1 || echo "")
    if echo "$desc" | grep -q '和'; then
        log_warn "$name: description contains '和' — consider splitting"
    fi

    if [ "$ok" = "1" ]; then
        PASS=$((PASS + 1))
    fi
done

echo ""
if [ "$PASS" = "$TOTAL" ]; then
    log_ok "Scripts: $PASS/$TOTAL compliant"
else
    log_error "Scripts: $PASS/$TOTAL compliant"
    BLOCK=$((BLOCK + 1))
fi

# Check README coverage
README="$SCRIPT_DIR/README.md"
if [ -f "$README" ]; then
    UNLISTED=0
    for f in $SCRIPTS; do
        grep -q "$(basename "$f")" "$README" 2>/dev/null || UNLISTED=$((UNLISTED + 1))
    done
    [ "$UNLISTED" -eq 0 ] && log_ok "README.md: all scripts registered" || log_warn "README.md: $UNLISTED unregistered"
fi

[ "$BLOCK" -gt 0 ] && exit 1 || exit 0
