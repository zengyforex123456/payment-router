#!/bin/bash
# pre-deploy-check.sh — 界面问题预防：部署前 6 项自动检测
# 用法: bash scripts/pre-deploy-check.sh
# 退出: 0=全部通过  非0=有阻断项
set -euo pipefail
PASS=0; FAIL=0; WARN=0
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
ok()   { echo -e "  [${GREEN}PASS${NC}] $1"; ((PASS++)); }
fail() { echo -e "  [${RED}FAIL${NC}] $1"; ((FAIL++)); }
warn() { echo -e "  [${YELLOW}WARN${NC}] $1"; ((WARN++)); }

echo "═══════════════════════════════════════"
echo "  Pre-Deploy UI Check — 6 Gate"
echo "═══════════════════════════════════════"
echo ""

# ═══ Gate 1: PHP 语法 ≠ __() 裸奔 ═══
echo "━━━ Gate 1: PHP Syntax + i18n Bugs ━━━"
# Check: __("...") outside <?= ?> in HTML attributes (alt/title/placeholder)
BARE_I18N=$(grep -rn '=[[:space:]]*__(' --include="*.php" views/ public/ 2>/dev/null | grep -v '<?=' | grep -v '^\s*//' || true)
if [[ -n "$BARE_I18N" ]]; then
    fail "Raw __() in HTML attributes (missing <?= ?>):"
    echo "$BARE_I18N" | head -5 | while read line; do echo "       $line"; done
else
    ok "No bare __() in HTML attributes"
fi

# Check: PHP syntax on all staged/new PHP files
STAGED_PHP=$(git diff --cached --name-only --diff-filter=ACM 2>/dev/null | grep '\.php$' || true)
if [[ -n "$STAGED_PHP" ]]; then
    for f in $STAGED_PHP; do
        php -l "$f" > /dev/null 2>&1 && ok "Syntax: $f" || fail "Syntax: $f"
    done
fi

# ═══ Gate 2: i18n 键完整性 ═══
echo ""
echo "━━━ Gate 2: i18n Key Completeness ━━━"
# Extract all __("key") calls from template files
USED_KEYS=$(grep -roh '__("[^"]*")' views/ public/ --include="*.php" 2>/dev/null | sed 's/__("\(.*\)")/\1/' | sort -u || true)
ZH_KEYS=$(php -r "\$zh = include 'lang/zh.php'; echo implode(\"\n\", array_keys(\$zh));" 2>/dev/null || true)
MISSING_KEYS=$(comm -23 <(echo "$USED_KEYS" | sort) <(echo "$ZH_KEYS" | sort) 2>/dev/null || true)
MISSING_COUNT=$(echo "$MISSING_KEYS" | grep -c '.' 2>/dev/null || echo 0)

if [[ "$MISSING_COUNT" -gt 0 ]]; then
    fail "$MISSING_COUNT i18n keys used in templates but missing from zh.php:"
    echo "$MISSING_KEYS" | head -10 | while read k; do echo "       $k"; done
else
    ok "All template __() keys exist in zh.php"
fi

# ═══ Gate 3: CSS 令牌一致性 ═══
echo ""
echo "━━━ Gate 3: CSS Token Consistency ━━━"
# Check: hardcoded colors in standalone pages (not using CSS variables)
HARDCODED_COLORS=$(grep -rn '#4f46e5\|#4338ca\|#6366f1\|#eef2ff' public/ --include="*.php" --include="*.css" 2>/dev/null | grep -v 'var(--' || true)
if [[ -n "$HARDCODED_COLORS" ]]; then
    warn "Hardcoded legacy colors found (not using CSS tokens):"
    echo "$HARDCODED_COLORS" | head -5 | while read line; do echo "       $line"; done
else
    ok "No hardcoded legacy colors (all use CSS tokens)"
fi

# ═══ Gate 4: 字体引用检查 ═══
echo ""
echo "━━━ Gate 4: Font Reference ━━━"
# Check: 'Inter' referenced without @font-face or CDN link
INTER_REFS=$(grep -rn "font-family.*Inter" public/ views/ --include="*.php" --include="*.css" 2>/dev/null || true)
HAS_FONTFACE=$(grep -rl "@font-face.*Inter" public/assets/css/ 2>/dev/null || true)
if [[ -n "$INTER_REFS" ]] && [[ -z "$HAS_FONTFACE" ]]; then
    fail "Inter font referenced but no @font-face found"
elif [[ -n "$INTER_REFS" ]]; then
    ok "Inter font: @font-face OK + references found"
else
    ok "No Inter references (using system fonts)"
fi

# ═══ Gate 5: 部署文件校验 ═══
echo ""
echo "━━━ Gate 5: Deploy File Integrity ━━━"
# Check: all staged files exist and are readable
STAGED_FILES=$(git diff --cached --name-only --diff-filter=ACM 2>/dev/null || true)
if [[ -n "$STAGED_FILES" ]]; then
    for f in $STAGED_FILES; do
        [[ -f "$f" ]] && [[ -r "$f" ]] && ok "Exists: $f" || fail "Missing: $f"
    done
fi

# ═══ Gate 6: OPcache 感知 ═══
echo ""
echo "━━━ Gate 6: PHP Files Changed ━━━"
PHP_CHANGED=$(echo "$STAGED_FILES" | grep '\.php$' 2>/dev/null || true)
if [[ -n "$PHP_CHANGED" ]]; then
    warn "PHP files changed — OPcache must be restarted after deploy:"
    echo "$PHP_CHANGED" | head -5 | while read f; do echo "       $f"; done
else
    ok "No PHP files changed (OPcache restart not needed)"
fi

# ═══ Summary ═══
echo ""
echo "═══════════════════════════════════════"
printf "  PASS: %d  FAIL: %d  WARN: %d\n" $PASS $FAIL $WARN
echo "═══════════════════════════════════════"

if [[ $FAIL -gt 0 ]]; then
    echo "❌ BLOCKED — fix FAIL items before deploy"
    exit 1
elif [[ $WARN -gt 0 ]]; then
    echo "🟡 WARNING — can deploy but check WARN items"
    exit 0
else
    echo "✅ READY — safe to deploy"
    exit 0
fi
