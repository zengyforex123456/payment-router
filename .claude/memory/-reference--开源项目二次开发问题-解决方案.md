---
name: -reference--开源项目二次开发问题-解决方案
description: 开源二开5层残留+法律/技术债问题及解决方案(WebSearch+Converge实战)
metadata:
  type: project
---

# [reference] 开源项目二次开发问题+解决方案

**检测模式**: 开源二开|品牌残留|去身份化|fork rebrand

**根因**: 改名含多层(命名空间/注释/文案/key/logo),只改一层留残留;license只授权代码不授权品牌

**修复**: ①全surface审计(rg扫品牌名) ②选干净名(查商标/包名) ③一次commit批量改 ④新建logo不改原图 ⑤NOTICE记录fork来源+原版权 ⑥品牌门禁防回归(brand-check.cjs) ⑦技术债:OpenRewrite自动重构/增量PR<500行/green-to-green测试

**验证**: brand-check扫描0残留;Converge 457文件品牌清零

法律:license≠trademark,品牌名受商标法保护(Apache2.0§6明确),X-community后缀会收C&D。品牌残留5层:L1命名空间/L2注释/L3UI文案/L4主题名key/L5logo资源。技术债:每10万行~$36万债,SATD方法更复杂bug多,zombie code逃避静态分析。上游分歧成本:merge疲劳/CVE双份/社区隔离。