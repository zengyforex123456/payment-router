#!/bin/bash
# agent-scout.sh — AI Agent 项目侦察脚本
# 一次调用输出项目 UI 现状，Agent 无需逐条 grep/ls/cat
# 用法: bash scripts/agent-scout.sh

echo "═══════════════════════════════════════"
echo "  Converge Agent 侦察报告"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "═══════════════════════════════════════"
echo ""

# ═══ 1. 设计令牌 ═══
echo "## 设计令牌"
if [ -f "../converge-ui/tokens/design-tokens.json" ]; then
    echo "  来源: ../converge-ui/tokens/design-tokens.json"
    echo "  颜色: $(node -e "const t=require('../converge-ui/tokens/design-tokens.json');console.log(Object.keys(t.colors).join(', '))" 2>/dev/null || echo 'N/A')"
    echo "  间距: $(node -e "const t=require('../converge-ui/tokens/design-tokens.json');console.log(Object.keys(t.spacing).join(', '))" 2>/dev/null || echo 'N/A')"
else
    echo "  ⚠️ 令牌文件未找到"
fi
echo ""

# ═══ 2. 现有 Alpine 组件 ═══
echo "## 现有 Alpine 组件"
COMPONENTS_DIR="public/assets/js/components"
if [ -d "$COMPONENTS_DIR" ]; then
    echo "  目录: $COMPONENTS_DIR"
    ls "$COMPONENTS_DIR"/*.js 2>/dev/null | while read f; do
        NAME=$(basename "$f")
        SIZE=$(wc -c < "$f")
        echo "  - $NAME ($SIZE bytes)"
    done
else
    echo "  ⚠️ 组件目录未找到: $COMPONENTS_DIR"
fi
echo ""

# ═══ 3. 布局文件 ═══
echo "## 全局布局"
SHELL="templates/layout/_shell.php"
if [ -f "$SHELL" ]; then
    LINES=$(wc -l < "$SHELL")
    echo "  PageShell: $SHELL ($LINES 行)"
    echo "  JS 加载: $(grep -c '<script' "$SHELL") 个 script 标签"
else
    echo "  ⚠️ PageShell 未找到, 检查 converge-ui:"
    ls ../converge-ui/views/layout/ 2>/dev/null
fi
echo ""

# ═══ 4. 现有 CSS ═══
echo "## CSS 资源"
CSS_DIR="public/assets/css"
if [ -d "$CSS_DIR" ]; then
    ls "$CSS_DIR"/*.css 2>/dev/null | while read f; do
        echo "  - $(basename "$f")"
    done
else
    echo "  ⚠️ CSS 目录未找到"
fi
echo ""

# ═══ 5. PHP 视图列表 ═══
echo "## PHP 视图"
VIEWS_DIR="templates"
if [ -d "$VIEWS_DIR" ]; then
    echo "  页面文件:"
    ls "$VIEWS_DIR"/*.php 2>/dev/null | while read f; do
        echo "  - $(basename "$f")"
    done
    echo "  组件文件:"
    ls "$VIEWS_DIR"/_*.php 2>/dev/null | while read f; do
        echo "  - $(basename "$f")"
    done
fi
echo ""

# ═══ 6. Git 状态 ═══
echo "## Git 状态"
echo "  分支: $(git branch --show-current 2>/dev/null || echo 'N/A')"
echo "  未提交: $(git status --short 2>/dev/null | wc -l) 文件"

echo ""
echo "═══════════════════════════════════════"
echo "  侦察完成"
echo "═══════════════════════════════════════"
