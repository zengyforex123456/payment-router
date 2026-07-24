---
name: php-close-tag-in-comment-bug
description: PHP注释里的?>提前闭合标签→整段逻辑当文本输出→下游变量未定义→页面半渲染
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 6b64e325-9230-426f-99f5-4abfed69c26f
---

# PHP `?>` 藏在 `//` 注释里 → 页面吐源码 (2026-07-11 Converge实战)

**检测模式**: 页面上出现原始PHP源码文本(如 "require_once __DIR__..."、"$db = new mysqli") + 该页交互元素(按钮/表单)神秘消失。

**根因**: `// <?=__("lp.title")?> CRUD Page` —— `//` 注释里的 `?>` **仍会闭合PHP标签**(PHP解析器不认注释)。从该行到下一个`<?php`/`<?=`之间的所有代码被当HTML文本输出、**从不执行**。于是后续依赖的变量($action/$db/$list)全未定义 → `if($action==='list')`为假 → 列表/表单分支都不渲染。页面头部(纯HTML)照常显示,造成"半好半坏"的迷惑现象。

**修复**: 移除注释里的 `<?=...?>`(或任何 `?>`)。`// <?=__("lp.title")?> CRUD Page` → `// Landing Pages — CRUD Page`。

**验证**: 部署后 E2E UT1(建LP→列表出现)从30s超时→4s通过; 表单重新渲染。

**元教训**: 静态 grep 会误判。我先前 grep "funnel-builder" 零命中 → 误断"构建器是孤儿页(N1)"; E2E 实测 DOM 快照显示侧边栏明明有 "🏗️ LP Builder" 入口。**实测(Playwright DOM snapshot) > 静态搜索**。审计结论必须用运行时证据复核。关联 [[converge-nofault-audit]] [[golden-signals-synthetic-monitoring]]
