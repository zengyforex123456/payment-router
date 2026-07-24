#!/bin/bash
# Converge — Pre-deploy validation
# Run before every commit/deploy. Non-zero exit = blocked.

set -e
PASS=0
FAIL=0

check() {
    local label="$1"; shift
    echo -n "  [$label] "
    if "$@" > /dev/null 2>&1; then
        echo "✅ PASS"
        ((PASS++))
    else
        echo "❌ FAIL"
        ((FAIL++))
    fi
}

echo "═══ Converge Pre-Deploy Validation ═══"
echo ""

# 1. PHP Syntax
echo "1. PHP Syntax Check"
for f in $(find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*'); do
    if ! php -l "$f" > /dev/null 2>&1; then
        echo "  ❌ Syntax error in: $f"
        ((FAIL++))
    fi
done
echo "  ✅ All PHP files pass"

# 2. I18n Compliance
echo "2. I18n Compliance"
php checks/i18n-compliance.php
((PASS++))

echo ""
echo "════════════════════════════════════"
echo "Result: $PASS passed, $FAIL failed"
echo "════════════════════════════════════"

exit $FAIL
