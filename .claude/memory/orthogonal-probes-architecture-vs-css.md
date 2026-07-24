---
name: orthogonal-probes-architecture-vs-css
description: 自愈探针分层设计 — 架构层探针和CSS层探针正交，修复动作永不交叉
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# 正交探针架构 — ArchitectureProbe ⊥ LayoutProbe

**检测模式**: 自愈引擎中架构层修复和CSS层修复混在一起，触发误操作
**根因**: 架构层（决定"显示什么"）和CSS层（决定"如何显示"）是正交关注点，探针和修复动作必须分层
**影响**: 布局溢出误触发PHP模板回滚 / 权限泄漏误触发CSS文件回滚

**修复**:
1. `ArchitectureProbe` — 架构层：HTTP状态码、PHP错误泄漏、权限内容门禁、DOM存在性 → 修复：回滚PHP模板、注入$can默认值。`layer: 'architecture'`
2. `LayoutProbe` — CSS层：CSS文件完整性、硬编码颜色、viewport meta、关键选择器 → 修复：回滚CSS文件、注入overflow修复。`layer: 'css'`
3. 两层集成到 `SelfProver`（8→10探针），各自独立运行
4. 隔离契约：架构探针的修复方法永不引用 `.css` 文件；布局探针的修复方法永不引用 `templates/` 或 `$can`

**验证**: 9 tests, 41 assertions, 含隔离契约验证
**关键**: 缺失DOM元素在LayoutProbe中标记为 `note`（架构层问题），不触发CSS修复
