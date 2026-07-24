#!/bin/bash
# upload-common.sh — 上传公共函数库 (断点续传·原子操作·校验)
# 用法: source "$SCRIPT_DIR/lib/upload-common.sh"
#
# 依赖: colors.sh (C_RED C_GREEN C_YELLOW C_CYAN C_BOLD C_RESET)
#       common.sh (log_info log_ok log_warn log_error die)

# ── 默认配置 (环境变量可覆写) ──
UPLOAD_HOST="${UPLOAD_HOST:-}"
UPLOAD_PORT="${UPLOAD_PORT:-22}"
UPLOAD_USER="${UPLOAD_USER:-root}"
UPLOAD_REMOTE_ROOT="${UPLOAD_REMOTE_ROOT:-/var/www/converge}"
UPLOAD_STAGING_DIR="${UPLOAD_STAGING_DIR:-}"
UPLOAD_KEEP_VERSIONS="${UPLOAD_KEEP_VERSIONS:-5}"       # 保留最近 N 个版本
UPLOAD_CHUNK_SIZE="${UPLOAD_CHUNK_SIZE:-1048576}"        # HTTP 分块: 1MB
UPLOAD_RETRY_MAX="${UPLOAD_RETRY_MAX:-5}"                # 最大重试次数
UPLOAD_RETRY_DELAY="${UPLOAD_RETRY_DELAY:-3}"            # 重试间隔(秒)
UPLOAD_EXCLUDE_FILE="${UPLOAD_EXCLUDE_FILE:-}"
MANIFEST_FILE="${MANIFEST_FILE:-.upload-manifest.json}"

# ── 自动生成 staging 路径 ──
if [ -z "$UPLOAD_STAGING_DIR" ]; then
    DEPLOY_TS="$(date +%Y%m%d-%H%M%S)"
    UPLOAD_STAGING_DIR="${UPLOAD_REMOTE_ROOT}-staging-${DEPLOY_TS}"
fi

# ═══════════════════════════════════════
# 指纹: 整个上传会话的唯一标识
# ═══════════════════════════════════════
gen_fingerprint() {
    echo "upload-$(date +%Y%m%d-%H%M%S)-$(head -c 4 /dev/urandom 2>/dev/null | xxd -p 2>/dev/null || echo "0000")"
}

# ═══════════════════════════════════════
# 本地文件清单 + SHA256 (用于校验)
# ═══════════════════════════════════════
generate_manifest() {
    local src_dir="$1" output_file="${2:-$MANIFEST_FILE}"

    log_info "生成文件清单: $src_dir → $output_file"

    cd "$src_dir" || die "无法进入: $src_dir"

    # 生成 JSON 清单: 每个文件的相对路径 + SHA256 + 大小
    echo '{' > "$output_file"
    echo '  "generated_at": "'$(date -Iseconds)'",' >> "$output_file"
    echo '  "source_dir": "'$(basename "$src_dir")'",' >> "$output_file"
    echo '  "files": [' >> "$output_file"

    local first=true total_size=0 file_count=0
    while IFS= read -r file; do
        [ -z "$file" ] && continue
        # 跳过排除项
        case "$file" in
            .git/*|vendor/*|node_modules/*|storage/*|.claude/*|*.db|*.bak|$MANIFEST_FILE) continue ;;
        esac

        local sha=$(sha256sum "$file" 2>/dev/null | awk '{print $1}')
        local size=$(stat -c%s "$file" 2>/dev/null || echo 0)

        [ "$first" = false ] && echo ',' >> "$output_file"
        first=false

        # JSON 需要转义路径中的反斜杠和引号
        local escaped_file=$(echo "$file" | sed 's/\\/\\\\/g; s/"/\\"/g')
        printf '    {"path":"%s","sha256":"%s","size":%d}' "$escaped_file" "$sha" "$size" >> "$output_file"

        total_size=$((total_size + size))
        file_count=$((file_count + 1))
    done < <(find . -type f 2>/dev/null)

    echo '' >> "$output_file"
    echo '  ],' >> "$output_file"
    echo '  "total_files": '$file_count',' >> "$output_file"
    echo '  "total_size": '$total_size >> "$output_file"
    echo '}' >> "$output_file"

    log_ok "清单: $file_count 文件, $(numfmt --to=iec $total_size 2>/dev/null || echo "${total_size}B")"
    cd - >/dev/null
}

# ═══════════════════════════════════════
# 服务端校验 manifest
# ═══════════════════════════════════════
verify_remote_manifest() {
    local remote="$1" staging_dir="$2" manifest_path="${3:-$MANIFEST_FILE}"

    log_info "远程校验文件完整性..."

    ssh -p "$UPLOAD_PORT" "$remote" bash -s << ENDVERIFY
set -e
STAGING="$staging_dir"
MANIFEST="\$STAGING/$manifest_path"

cd "\$STAGING" || { echo "FAIL: cannot cd \$STAGING"; exit 1; }

errors=0
total=\$(python3 -c "import json; print(len(json.load(open('\$MANIFEST'))['files']))" 2>/dev/null || echo 0)

while IFS= read -r line; do
    path=\$(echo "\$line" | python3 -c "import sys,json; print(json.loads(sys.stdin.read())['path'])" 2>/dev/null)
    expected=\$(echo "\$line" | python3 -c "import sys,json; print(json.loads(sys.stdin.read())['sha256'])" 2>/dev/null)
    [ -z "\$path" ] && continue

    if [ ! -f "\$path" ]; then
        echo "MISSING: \$path"
        errors=\$((errors + 1))
        continue
    fi

    actual=\$(sha256sum "\$path" | awk '{print \$1}')
    if [ "\$actual" != "\$expected" ]; then
        echo "MISMATCH: \$path (expected: \$expected, got: \$actual)"
        errors=\$((errors + 1))
    fi
done < <(python3 -c "
import json
with open('\$MANIFEST') as f:
    m = json.load(f)
for f in m['files']:
    print(json.dumps(f))
" 2>/dev/null)

if [ \$errors -eq 0 ]; then
    echo "OK: \$total files verified, 0 errors"
else
    echo "FAIL: \$errors files corrupted or missing"
    exit 1
fi
ENDVERIFY

    return $?
}

# ═══════════════════════════════════════
# 原子切换: staging → production
# 支持两种模式: symlink (零停机) 或 mv (同分区)
# ═══════════════════════════════════════
atomic_swap() {
    local remote="$1" staging_dir="$2" production_dir="$3"

    log_info "原子切换: $staging_dir → $production_dir"

    ssh -p "$UPLOAD_PORT" "$remote" bash -s << ENDSWAP
set -e
STAGING="$staging_dir"
PROD="$production_dir"
KEEP=$UPLOAD_KEEP_VERSIONS

echo "  ① 确认 staging 完整..."
[ -d "\$STAGING" ] || { echo "FAIL: staging 不存在"; exit 1; }
[ -f "\$STAGING/$MANIFEST_FILE" ] || { echo "FAIL: manifest 缺失"; exit 1; }

echo "  ② 备份当前版本..."
if [ -d "\$PROD" ]; then
    BACKUP="\${PROD}-backup-\$(date +%Y%m%d-%H%M%S)"
    cp -al "\$PROD" "\$BACKUP" 2>/dev/null && echo "     硬链接备份: \$BACKUP" || {
        echo "     (跳过备份: 硬链接不可用，使用 staging 作为回滚点)"
    }
fi

echo "  ③ 原子切换 (同分区 rename = 原子操作)..."
# 如果 PROD 存在，先移到 old
if [ -d "\$PROD" ]; then
    OLD="\${PROD}-old-\$(date +%Y%m%d-%H%M%S)"
    mv "\$PROD" "\$OLD"
fi
# 原子 rename (同分区保证原子性)
mv "\$STAGING" "\$PROD"
echo "      ✅ \$STAGING → \$PROD"

echo "  ④ 清理旧版本 (保留最近 \$KEEP 个)..."
for d in \$(ls -dt \${PROD}-old-* \${PROD}-backup-* \${PROD}-staging-* 2>/dev/null | tail -n +\$((KEEP + 1))); do
    echo "     清理: \$d"
    rm -rf "\$d"
done

echo "  ⑤ 设置权限..."
chown -R www-data:www-data "\$PROD" 2>/dev/null || chmod -R 755 "\$PROD"
find "\$PROD" -type d -exec chmod 755 {} \; 2>/dev/null || true
find "\$PROD" -type f -exec chmod 644 {} \; 2>/dev/null || true

echo "  ✅ 原子切换完成"
ENDSWAP

    return $?
}

# ═══════════════════════════════════════
# 服务端重启 (Docker compose)
# ═══════════════════════════════════════
remote_restart() {
    local remote="$1" production_dir="$2"

    log_info "远程重启服务..."

    ssh -p "$UPLOAD_PORT" "$remote" bash -s << ENDRESTART
set -e
PROD="$production_dir"
cd "\$PROD" || { echo "FAIL: 无法进入 \$PROD"; exit 1; }

# Docker 模式
if [ -f "docker-compose.server.yml" ]; then
    echo "  重启 Docker 容器..."
    docker compose -f docker-compose.server.yml up -d --build app 2>/dev/null || \
    docker compose -f docker-compose.server.yml up -d app 2>/dev/null || \
    docker compose up -d app 2>/dev/null || true

    echo "  等待健康检查..."
    for i in \$(seq 1 30); do
        if curl -sf http://localhost/health >/dev/null 2>&1; then
            echo "  ✅ 健康检查通过"
            # 清除 OPcache
            docker exec converge-app-1 php -r "if(function_exists('opcache_reset')) opcache_reset();" 2>/dev/null || true
            exit 0
        fi
        sleep 2
    done
    echo "  ⚠️  健康检查超时 (服务可能仍在启动)"
else
    # 非 Docker 模式: 清 OPcache
    if [ -f "\$PROD/public/index.php" ]; then
        php -r "if(function_exists('opcache_reset')) opcache_reset();" 2>/dev/null || true
    fi
fi
ENDRESTART
}

# ═══════════════════════════════════════
# 回滚到上一个版本
# ═══════════════════════════════════════
rollback_remote() {
    local remote="$1" production_dir="$2"

    log_warn "触发回滚..."

    ssh -p "$UPLOAD_PORT" "$remote" bash -s << ENDROLLBACK
set -e
PROD="$production_dir"

# 找最近备份
BACKUP=\$(ls -dt \${PROD}-backup-* \${PROD}-old-* 2>/dev/null | head -1)
if [ -z "\$BACKUP" ]; then
    echo "FAIL: 没有可回滚的备份"
    exit 1
fi

echo "  回滚到: \$BACKUP"
# 当前出问题的版本移走
if [ -d "\$PROD" ]; then
    mv "\$PROD" "\${PROD}-failed-\$(date +%Y%m%d-%H%M%S)"
fi
# 恢复备份
mv "\$BACKUP" "\$PROD"
echo "  ✅ 回滚完成"

# 重启
cd "\$PROD"
if [ -f "docker-compose.server.yml" ]; then
    docker compose -f docker-compose.server.yml up -d app 2>/dev/null || true
fi
ENDROLLBACK
}

# ═══════════════════════════════════════
# 默认排除规则
# ═══════════════════════════════════════
get_default_excludes() {
    cat <<EOF
.git/
vendor/
node_modules/
storage/
.claude/
*.db
*.bak
$MANIFEST_FILE
.DS_Store
Thumbs.db
*.log
cache/
tmp/
EOF
}

# ═══════════════════════════════════════
# 连接性检查
# ═══════════════════════════════════════
check_connectivity() {
    local remote="$1"

    log_info "检查到 $remote 的连接..."
    if ssh -p "$UPLOAD_PORT" -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new "$remote" "echo OK" 2>/dev/null | grep -q OK; then
        log_ok "SSH 连接正常"
        return 0
    else
        log_error "SSH 连接失败: $remote"
        return 1
    fi
}

# ═══════════════════════════════════════
# 磁盘空间检查
# ═══════════════════════════════════════
check_disk_space() {
    local remote="$1" required_mb="${2:-500}"

    log_info "检查远程磁盘空间 (需要 ≥${required_mb}MB)..."
    local avail=$(ssh -p "$UPLOAD_PORT" "$remote" "df -BM --output=avail /var/www 2>/dev/null | tail -1 | tr -d ' M'" 2>/dev/null || echo 0)
    if [ "$avail" -lt "$required_mb" ]; then
        log_error "磁盘空间不足: ${avail}MB 可用, 需要 ${required_mb}MB"
        return 1
    fi
    log_ok "磁盘空间充足: ${avail}MB"
    return 0
}
