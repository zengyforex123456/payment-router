#!/bin/bash
# run-e2e.sh — Converge E2E 视觉回归 / Token 断言 / 无障碍审计
set -e
E2E_URL="${E2E_URL:-http://localhost:8080}"
DIR="data/tests/E2E"
echo "E2E: ${E2E_URL}"
case "${1:---quick}" in
  --full)   npx playwright test "$DIR/" ;;
  --visual) npx playwright test "$DIR/visual-regression.spec.js" "$DIR/token-aware-visual.spec.js" ;;
  --token)  npx playwright test "$DIR/token-render-assert.spec.js" ;;
  --a11y)   npx playwright test "$DIR/a11y-audit.spec.js" "$DIR/dark-mode-audit.spec.js" ;;
  *)        npx playwright test "$DIR/visual-regression.spec.js" "$DIR/token-render-assert.spec.js" "$DIR/dark-mode-audit.spec.js" ;;
esac
echo "Done"
