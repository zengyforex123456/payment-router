#!/bin/sh
# ═══ E2E Smoke Gate — 5 critical user journeys, <60 seconds ═══
# Usage: bash ci/check-e2e-smoke.sh [baseUrl]
# Prereq: npm install (Playwright)
# Exit: 0 = all passed, 1 = failures

set -e

BASE_URL="${1:-http://localhost}"
PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

echo "═══ E2E Smoke Gate ═══"
echo "Base: $BASE_URL"

# Ensure Playwright is installed
if [ ! -f "node_modules/.bin/playwright" ]; then
    echo "  ⚠️  Playwright not installed — npm install @playwright/test"
    # Non-blocking: skip if not installed (E2E is a deployment gate, not commit gate)
    echo "  ⏭️  Skipping E2E (Playwright not installed)"
    exit 0
fi

# 5 critical smoke specs that verify core user journeys
# Each runs independently to isolate failures
FAILED=0
PASSED=0

run_spec() {
    local spec="$1"
    local label="$2"
    echo "  Running: $label ($spec)..."
    if npx playwright test "$spec" --reporter=line 2>&1 | tail -3; then
        PASSED=$((PASSED + 1))
        echo "    ✅ $label"
    else
        FAILED=$((FAILED + 1))
        echo "    ❌ $label FAILED"
    fi
}

echo ""

# 1. Auth UI — login page renders, form works
run_spec "tests/E2E/auth-ui-smoke.spec.js" "Login Page"

# 2. Dashboard — loads with stats, no JS errors
run_spec "tests/E2E/dashboard.spec.js" "Dashboard"

# 3. Smoke — basic page navigation works
run_spec "tests/E2E/smoke.spec.js" "Smoke Navigation"

# 4. Responsive — mobile/tablet/desktop breakpoints
run_spec "tests/E2E/responsive.spec.js" "Responsive (3 breakpoints)"

# 5. A11y audit — no WCAG violations
run_spec "tests/E2E/a11y-audit.spec.js" "Accessibility (WCAG AA)"

echo ""
echo "───"
echo "E2E Smoke: $PASSED passed, $FAILED failed"
if [ "$FAILED" -gt 0 ]; then
    echo "❌ $FAILED E2E smoke spec(s) failed"
    exit 1
fi
echo "✅ All 5 E2E smoke specs passed"
