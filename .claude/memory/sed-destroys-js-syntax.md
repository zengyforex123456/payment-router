---
name: sed-destroys-js-syntax
description: sed 破坏 JS 语法 → 部署线上报错 → node --check 门禁阻断
metadata: 
  node_type: memory
  type: feedback
  originSessionId: c898435f-e7cd-482c-9fbb-6adbb847449c
---

# sed 破坏 JS 文件 — 检测·预防·替代方案

**检测模式**: 浏览器 Console 报 `Uncaught SyntaxError: Unexpected token ')'` + 行号指向文件末尾

**根因**: sed 对 JS 文件做字符串替换时，未处理特殊字符 (`/`, `$`, `&`, `\`) 或破坏了括号闭合。sed 默认按行处理，跨行替换易出错。

**实例**: Converge 侧边栏 Alpine 组件
- 用 sed 将 `document.addEventListener('alpine:init', function () {` 改为 `function registerDockNav() {`
- sed 未同步修改结尾 `});` → 括号不匹配 → SyntaxError
- 4 个 JS 文件全部损坏，侧边栏完全失效

**预防 (3 层)**:

| 层 | 方案 | 文件 |
|---|------|------|
| L1 工具 | `scripts/safe-replace.js` 替代 sed — Node.js 原生, 零破坏, 自动备份 | `scripts/safe-replace.js` |
| L2 门禁 | `node --check` 加入 local-test.sh — 部署前自动检查所有 JS 语法 | `scripts/local-test.sh` |
| L3 规范 | `.eslintrc.json` — 开发阶段实时检测 | `.eslintrc.json` |

**safe-replace.js 用法**:
```bash
node scripts/safe-replace.js public/assets/js/app.js '旧文本' '新文本'
# → 自动备份 .bak → 替换 → 报告替换次数
```

**关联**: [[cdn-blocked-china-alpine-htmx]] [[docker-deploy-error-patterns]]
