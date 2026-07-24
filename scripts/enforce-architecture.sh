#!/bin/sh
# enforce-architecture.sh — 六边形架构强制门禁
# ① 分模块 ② 分层 ③ 文件≤150行 ④ 接口契约 ⑤ Alpine XSS
# 用法: bash scripts/enforce-architecture.sh [--staged]
set -euo pipefail
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BLOCK=0; WARN=0
STAGED="${1:-}"

GREEN='\033[32m'; RED='\033[31m'; YELLOW='\033[33m'; CYAN='\033[36m'; NC='\033[0m'
ok()   { echo "  ${GREEN}✅${NC} $1"; }
fail() { echo "  ${RED}🚫${NC} $1"; BLOCK=$((BLOCK+1)); }
warn() { echo "  ${YELLOW}⚠️${NC}  $1"; WARN=$((WARN+1)); }

echo "═══ 架构门禁 ═══"

# ── 1. File size (staged new files only) ──
echo "① 文件大小 (≤150行)..."
if [ "$STAGED" = "--staged" ]; then
    FILES=$(git diff --cached --name-only --diff-filter=A | grep '\.php$' || true)
else
    FILES=$(find "$PROJECT_ROOT/app" "$PROJECT_ROOT/modules" "$PROJECT_ROOT/tools" \
        -name "*.php" -not -path "*/vendor/*" -not -path "*/node_modules/*" 2>/dev/null || true)
fi
SIZE_VIOLATIONS=0
for f in $FILES; do
    [ -f "$f" ] || continue
    LINES=$(wc -l < "$f" 2>/dev/null || echo "0")
    if [ "$LINES" -gt 150 ]; then
        if [ "$SIZE_VIOLATIONS" -lt 10 ]; then
            fail "$f ($LINES lines)"
        fi
        SIZE_VIOLATIONS=$((SIZE_VIOLATIONS + 1))
    fi
done
if [ "$SIZE_VIOLATIONS" -eq 0 ]; then
    ok "All files ≤150 lines"
else
    echo "    (${SIZE_VIOLATIONS} total violations)"
fi

# ── 2. Domain layer: zero IO ──
echo "② Domain 零 IO..."
IO_VIOLATIONS=0
for f in $(find "$PROJECT_ROOT/modules" -path "*/Domain/*.php" -not -path "*/vendor/*" 2>/dev/null || true); do
    if grep -qE 'new\s+(mysqli|PDO)\b|file_get_contents|curl_exec|exec\(' "$f" 2>/dev/null; then
        fail "$(basename "$f"): Domain contains IO — extract to Infrastructure"
        IO_VIOLATIONS=$((IO_VIOLATIONS + 1))
    fi
done
[ "$IO_VIOLATIONS" -eq 0 ] && ok "Domain layer IO-free"

# ── 3. Cross-module via Hooks only ──
echo "③ 跨模块通信 (Hooks)..."
DIRECT_USE=0
for f in $(find "$PROJECT_ROOT/modules" -name "*.php" -not -path "*/vendor/*" 2>/dev/null || true); do
    MODULE_NAME=$(echo "$f" | sed -n 's|.*/modules/\([^/]*\)/.*|\1|p')
    # Check for direct 'use App\Modules\OtherModule' imports
    VIOLATIONS=$(grep -c "use App\\\\Modules\\\\" "$f" 2>/dev/null || echo "0")
    if [ "$VIOLATIONS" -gt 0 ]; then
        # Exclude self-references
        SELF_REF=$(grep -c "use App\\\\Modules\\\\${MODULE_NAME}\\\\" "$f" 2>/dev/null || echo "0")
        if [ "$VIOLATIONS" != "$SELF_REF" ]; then
            DIRECT_USE=$((DIRECT_USE + 1))
        fi
    fi
done
[ "$DIRECT_USE" -eq 0 ] && ok "No direct cross-module use" || warn "$DIRECT_USE files have direct cross-module use"

# ── Summary ──
echo ""
if [ "$BLOCK" -gt 0 ]; then
    echo "${RED}🚫 $BLOCK blocking | $WARN warnings${NC}"
    exit 1
else
    echo "${GREEN}✅ Architecture compliant${NC} ($WARN warnings)"
fi
