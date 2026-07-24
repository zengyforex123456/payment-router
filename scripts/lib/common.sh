#!/bin/sh
# common.sh — 脚本公共函数库 (所有脚本 source 此文件即可)
# 用法: SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
#       source "$SCRIPT_DIR/lib/colors.sh"
#       source "$SCRIPT_DIR/lib/common.sh"

# 致命错误，退出
die() { log_error "$1"; exit 1; }

# 条件确认 (非交互模式自动跳过)
confirm() {
    if [ -t 0 ]; then
        echo -e "${C_YELLOW}⚠️  $1 输入 yes 确认:${C_RESET}"
        read -r answer
        [ "$answer" = "yes" ] || die "用户取消"
    fi
}

# 等待服务就绪
wait_for_url() {
    local url="$1" timeout="${2:-60}" label="${3:-service}"
    log_info "等待 $label ($url)..."
    local i=0
    while [ $i -lt $((timeout / 2)) ]; do
        if curl -sf "$url" >/dev/null 2>&1; then
            log_ok "$label 就绪 (${i}s)"
            return 0
        fi
        i=$((i + 2)); sleep 2
    done
    die "$label 超时 ($timeout s)"
}

# 检查命令是否存在
require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "缺少命令: $1 (请先安装)"
}

# 项目根目录
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
