#!/bin/sh
# colors.sh — 终端颜色输出 (零依赖, 所有脚本 source 此文件即可)
# 用法: source "$(dirname "$0")/lib/colors.sh"
#       log_info "Starting..."

# 颜色码
C_RED='\033[31m'
C_GREEN='\033[32m'
C_YELLOW='\033[33m'
C_BLUE='\033[34m'
C_CYAN='\033[36m'
C_BOLD='\033[1m'
C_RESET='\033[0m'

# 带时间戳的日志函数
log_info()  { echo -e "${C_CYAN}[$(date +%H:%M:%S)]${C_RESET} $1"; }
log_ok()    { echo -e "${C_GREEN}[$(date +%H:%M:%S)]${C_RESET} ✅ $1"; }
log_warn()  { echo -e "${C_YELLOW}[$(date +%H:%M:%S)]${C_RESET} ⚠️  $1"; }
log_error() { echo -e "${C_RED}[$(date +%H:%M:%S)]${C_RESET} ❌ $1"; }
log_title() { echo -e "\n${C_BOLD}═══ $1 ═══${C_RESET}"; }
