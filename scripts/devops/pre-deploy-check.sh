#!/bin/bash
# ═══ D0C: 部署前自动检查 — 阻断常见 Dokku 陷阱 ═══
# 单一职责: git push 前验证 Dockerfile/Procfile/.deploy.json 合规
# 用法: bash pre-deploy-check.sh [project-dir]
# 层: L2 执行层

set -euo pipefail
PYTHON=$(command -v python 2>/dev/null || command -v python3 2>/dev/null || echo "python3")
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

# Convert /e/project/... → E:/project/... for Windows Python compatibility
win_path() { echo "$1" | sed 's|^/\([a-z]\)/|\U\1:/|'; }

PROJECT_DIR="${1:-.}"
PROJECT_DIR_WIN=$(win_path "$PROJECT_DIR")  # for Windows Python
PASS=0; FAIL=0; WARN=0

check() {
    local desc="$1" result="$2" fix="$3"
    if [ "$result" = "ok" ]; then
        echo -e "  ${GREEN}✅${NC} $desc"
        PASS=$((PASS + 1))
    elif [ "$result" = "warn" ]; then
        echo -e "  ${YELLOW}⚠️ ${NC} $desc — $fix"
        WARN=$((WARN + 1))
    else
        echo -e "  ${RED}❌${NC} $desc — $fix"
        FAIL=$((FAIL + 1))
    fi
}

echo "╔═══════════════════════════════════════╗"
echo "║  D0C: Pre-Deploy Check              ║"
echo "╚═══════════════════════════════════════╝"
echo "📂 $PROJECT_DIR"
echo ""

# ─── 0. 对象注册表刷新 (强制，预防管理盲区) ───
echo "── Registry ──"
REGISTRY_FILE="$PROJECT_DIR/reports/devops-registry.json"
mkdir -p "$PROJECT_DIR/reports"

# 检查 registry 新鲜度
if [ -f "$REGISTRY_FILE" ]; then
    # 超过 24 小时 → 告警
    last_refresh=$(python -c "import json; print(json.load(open('$REGISTRY_FILE',encoding='utf-8')).get('refreshed_at',''))" 2>/dev/null || echo "")
    if [ -n "$last_refresh" ]; then
        check "Registry 新鲜度" "warn" "超过24h未刷新 → 运行: bash scripts/devops/registry.sh refresh"
    else
        check "Registry 已初始化" "ok" ""
    fi
else
    check "Registry 已初始化" "warn" "registry.json 不存在 → 运行: bash scripts/devops/registry.sh refresh (部署不阻塞)"
fi

# ─── 1. Dockerfile 陷阱 ───
echo "── Dockerfile ──"

# 1.1 EXPOSE 端口检查 (Dokku web 标准是 5000)
dockerfile=$(find "$PROJECT_DIR" -maxdepth 3 -name "Dockerfile*" ! -name "*.node" | head -1)
if [ -n "$dockerfile" ]; then
    expose_port=$(grep -oP 'EXPOSE\s+\K\d+' "$dockerfile" 2>/dev/null || echo "")
    if [ -z "$expose_port" ]; then
        check "EXPOSE declared" "warn" "无 EXPOSE，Dokku 默认用 5000"
    elif [ "$expose_port" = "80" ] || [ "$expose_port" = "5000" ]; then
        check "EXPOSE=$expose_port (Dokku 标准)" "ok" ""
    elif [ "$expose_port" = "8080" ]; then
        check "EXPOSE=$expose_port" "fail" "改为 EXPOSE 5000！否则 nginx 监听 8080 而非 80"
    else
        check "EXPOSE=$expose_port" "warn" "非标准端口，确认 Dokku PORT 映射"
    fi
fi

# 1.2 多 Dockerfile 检查
dockerfiles=$(find "$PROJECT_DIR" -maxdepth 3 -name "Dockerfile*" ! -name "*.node" | wc -l)
if [ "$dockerfiles" -gt 1 ]; then
    check "多 Dockerfile ($dockerfiles 个)" "warn" "Dokku 默认用根目录 Dockerfile。设置 DOKKU_DOCKERFILE_PATH"
fi

# ─── 2. Procfile 检查 ───
echo "── Procfile ──"

if [ -f "$PROJECT_DIR/Procfile" ]; then
    if grep -q '\$PORT' "$PROJECT_DIR/Procfile" 2>/dev/null; then
        check "Procfile 使用 \$PORT" "ok" ""
    else
        check "Procfile 使用 \$PORT" "fail" "端口必须用 \$PORT 环境变量"
    fi
    if grep -q '^web:' "$PROJECT_DIR/Procfile" 2>/dev/null; then
        check "Procfile 有 web 进程" "ok" ""
    else
        check "Procfile 有 web 进程" "fail" "Dokku 需要 web 进程定义"
    fi
else
    check "Procfile 存在" "warn" "无 Procfile，Dokku 使用 Dockerfile CMD"
fi

# ─── 3. .deploy.json 检查 ───
echo "── .deploy.json ──"

if [ -f "$PROJECT_DIR/.deploy.json" ]; then
    check ".deploy.json 存在" "ok" ""
    # 验证 JSON 有效性
    $PYTHON -c "import json; json.load(open('$PROJECT_DIR_WIN/.deploy.json',encoding='utf-8'))" 2>/dev/null && \
        check ".deploy.json JSON 有效" "ok" "" || \
        check ".deploy.json JSON 有效" "fail" "JSON 格式错误"
else
    check ".deploy.json 存在" "fail" "项目缺少 .deploy.json 部署清单"
fi

# ─── 4. .env.vars.json 检查 ───
echo "── .env.vars.json ──"

if [ -f "$PROJECT_DIR/.env.vars.json" ]; then
    check ".env.vars.json 存在" "ok" ""
    # 检查 app_name 字段
    app_name=$($PYTHON -c "import json; print(json.load(open('$PROJECT_DIR_WIN/.env.vars.json',encoding='utf-8'))['app_name'])" 2>/dev/null || echo "")
    [ -n "$app_name" ] && check "app_name=$app_name" "ok" "" || check "app_name 声明" "fail" "缺少 app_name"
else
    check ".env.vars.json 存在" "fail" "运行: bash scripts/devops/sync-env.sh 生成"
fi

# ─── 5. 安全扫描 ───
echo "── Security ──"

# 5.1 硬编码密钥
if grep -rq 'API_KEY\|SECRET\|PASSWORD.*=' "$dockerfile" 2>/dev/null; then
    check "Dockerfile 无硬编码密钥" "fail" "密钥必须通过 dokku config:set 注入"
else
    check "Dockerfile 无硬编码密钥" "ok" ""
fi

# 5.2 .gitignore 包含 .env
if [ -f "$PROJECT_DIR/.gitignore" ]; then
    grep -q '\.env' "$PROJECT_DIR/.gitignore" 2>/dev/null && \
        check ".gitignore 排除 .env" "ok" "" || \
        check ".gitignore 排除 .env" "fail" "添加 .env* 到 .gitignore"
fi

# ─── 6. 入口脚本检查 ───
echo "── Entrypoint ──"

entrypoint=$(find "$PROJECT_DIR" -name "entrypoint.sh" -maxdepth 3 | head -1)
if [ -n "$entrypoint" ]; then
    # 6.1 mysqladmin ping vs PHP mysqli (MySQL 8.0 TLS 陷阱)
    if grep -q "new mysqli" "$entrypoint" 2>/dev/null; then
        check "entrypoint 用 mysqladmin ping" "fail" "PHP mysqli 在 MySQL 8.0 会 TLS 失败，改用 mysqladmin ping"
    elif grep -q "mysqladmin ping" "$entrypoint" 2>/dev/null; then
        check "entrypoint 用 mysqladmin ping" "ok" ""
    fi
fi

# ─── 7. 迁移覆盖检查 (新增 — 预防 company/plan 缺失) ───
echo "── Migration Coverage ──"
# 检查 register.php 需要的列是否被某个迁移覆盖
if [ -f "$PROJECT_DIR/public/register.php" ] 2>/dev/null; then
    if grep -q "INSERT INTO users.*company" "$PROJECT_DIR/public/register.php" 2>/dev/null; then
        if grep -rq "ADD COLUMN.*company" "$PROJECT_DIR/database/migrations/" 2>/dev/null; then
            check "register.php company/plan 列" "ok" ""
        else
            check "register.php company/plan 列" "fail" "缺迁移！register.php 需要 company/plan 列，但数据库迁移中没有"
        fi
    fi
fi
# 检查 login 查询需要的 is_active 列
if grep -rq "is_active" "$PROJECT_DIR/public/" 2>/dev/null; then
    if grep -rq "is_active" "$PROJECT_DIR/database/migrations/" 2>/dev/null; then
        check "login is_active 列" "ok" ""
    else
        check "login is_active 列" "fail" "缺迁移！login 查询需要 is_active 列"
    fi
fi

# ─── 8. MySQL TLS 检查 (新增 — 预防 #2 故障) ───
echo "── MySQL TLS ──"
if [ -f "$PROJECT_DIR/.env.vars.json" ]; then
    if grep -q "ssl-mode=DISABLED\|ssl=0" "$PROJECT_DIR/.env.vars.json" 2>/dev/null; then
        check "DATABASE_URL 禁用 SSL" "ok" ""
    elif grep -q "DATABASE_URL" "$PROJECT_DIR/.env.vars.json" 2>/dev/null; then
        check "DATABASE_URL 禁用 SSL" "fail" "MySQL 8.0 自签名证书会导致连接失败。在 DATABASE_URL 末尾加 ?ssl-mode=DISABLED"
    else
        check "DATABASE_URL 禁用 SSL" "warn" "未找到 DATABASE_URL 配置"
    fi
fi

# ─── 9. 域名 DNS 检查 (新增 — 预防部署后 502) ───
echo "── DNS ──"
if [ -f "$PROJECT_DIR/.deploy.json" ]; then
    domain=$($PYTHON -c "import json; print(json.load(open('$PROJECT_DIR_WIN/.deploy.json',encoding='utf-8')).get('domain',''))" 2>/dev/null || echo "")
    if [ -n "$domain" ]; then
        resolved=$(nslookup "$domain" 2>/dev/null | grep -c "137.184.225.93" || echo "0")
        if [ "$resolved" -gt 0 ] 2>/dev/null; then
            check "DNS: $domain → 137.184.225.93" "ok" ""
        else
            check "DNS: $domain" "warn" "$domain 未解析到 137.184.225.93，部署后网站无法访问"
        fi
    fi
fi

# ─── 10. 环境变量命名规范 (新增 — 统一 DB_USER/DB_NAME) ───
echo "── Env Var Naming ──"
if [ -f "$PROJECT_DIR/.env.vars.json" ]; then
    # 禁止 DB_USERNAME (应该是 DB_USER)
    if $PYTHON -c "import json; d=json.load(open('$PROJECT_DIR_WIN/.env.vars.json',encoding='utf-8')); assert 'DB_USERNAME' not in d.get('vars',{}), 'DB_USERNAME'" 2>/dev/null; then
        check "无 DB_USERNAME (应为 DB_USER)" "ok" ""
    else
        check "无 DB_USERNAME (应为 DB_USER)" "fail" "将 vars.DB_USERNAME 改为 vars.DB_USER"
    fi
    # 禁止 DB_DATABASE (应该是 DB_NAME)
    if $PYTHON -c "import json; d=json.load(open('$PROJECT_DIR_WIN/.env.vars.json',encoding='utf-8')); assert 'DB_DATABASE' not in d.get('vars',{}), 'DB_DATABASE'" 2>/dev/null; then
        check "无 DB_DATABASE (应为 DB_NAME)" "ok" ""
    else
        check "无 DB_DATABASE (应为 DB_NAME)" "fail" "将 vars.DB_DATABASE 改为 vars.DB_NAME"
    fi
    # 检查是否缺少 DB_NAME
    if $PYTHON -c "import json; d=json.load(open('$PROJECT_DIR_WIN/.env.vars.json',encoding='utf-8')); v=d.get('vars',{}); s=set(d.get('sensitive',[])); assert 'DB_NAME' in v or 'DB_NAME' in s, 'missing DB_NAME'" 2>/dev/null; then
        check "DB_NAME 已声明" "ok" ""
    else
        check "DB_NAME 已声明" "fail" ".env.vars.json 缺少 DB_NAME"
    fi
    # 检查 DATABASE_URL 包含 ssl-mode=DISABLED
    if $PYTHON -c "import json; d=json.load(open('$PROJECT_DIR_WIN/.env.vars.json',encoding='utf-8')); v=d.get('vars',{}); url=v.get('DATABASE_URL',''); assert 'ssl-mode=DISABLED' in url or 'ssl=0' in url, 'no SSL disable'" 2>/dev/null; then
        check "DATABASE_URL 禁用 SSL" "ok" ""
    else
        check "DATABASE_URL 禁用 SSL" "warn" "建议在 DATABASE_URL 末尾加 ?ssl-mode=DISABLED (MySQL 8.0 自签名证书)"
    fi
fi

# ─── Summary ───
echo ""
echo "──────────────────────────────────────"
echo -e "  ${GREEN}✅ Pass: $PASS${NC}  ${YELLOW}⚠ Warn: $WARN${NC}  ${RED}❌ Fail: $FAIL${NC}"
echo "──────────────────────────────────────"

if [ "$FAIL" -gt 0 ]; then
    echo -e "${RED}❌ Pre-deploy check FAILED — 修复后重试${NC}"
    exit 1
elif [ "$WARN" -gt 0 ]; then
    echo -e "${YELLOW}⚠️  Warnings present — 建议修复，不阻塞部署${NC}"
    echo -e "${GREEN}✅ Ready to deploy (with warnings)${NC}"
    exit 0
else
    echo -e "${GREEN}✅ Ready to deploy${NC}"
    exit 0
fi
