#!/bin/bash
# run-migrations-remote.sh — 运行迁移 103 + 106 (修复后)
set -e
cd /var/www/converge
echo "=== 103: Funnel Events ==="
docker exec -i converge-mysql-1 mysql -uroot -p"change-me-to-a-secure-password" converge < database/migrations/103_create_funnel_events.sql 2>&1 && echo "  OK: 103" || echo "  FAIL: 103"
echo "=== 106: Tenant FK ==="
docker exec -i converge-mysql-1 mysql -uroot -p"change-me-to-a-secure-password" converge < database/migrations/106_tenant_foreign_keys.sql 2>&1 && echo "  OK: 106" || echo "  FAIL: 106"
echo "=== Done ==="
