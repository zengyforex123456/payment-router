---
name: -reference--admin界面设计-功能问题及解决方案
description: admin dashboard 6大类界面问题(死链接/设计不一致/对比度/反馈/重复/过载)+解决方案(WebSearch+Converge实战)
metadata:
  type: project
---

# [reference] Admin界面设计+功能问题及解决方案

**检测模式**: 界面问题|死链接|admin dashboard|sidebar|设计不一致

**根因**: 缺设计系统统一+死链接未清+无可访问性检查

**修复**: 死链接:移除或补页面;设计:令牌系统(tokens.css);对比度:WCAG AA 4.5:1;反馈:骨架屏+toast;移动:768px折叠+44px触控

**验证**: 侧边栏死链清零;brand-check+arch-check门禁

①导航死链接→点击404(Converge:marketing-funnel/diagnostics) ②设计不一致缺设计系统→硬编码颜色(Converge 852处) ③对比度WCAG失败(需4.5:1) ④缺反馈(loading/toast) ⑤重复侧边栏代码 ⑥布局过载。解决:设计令牌/WCAG AA/骨架屏toast/共享组件/渐进披露/44px触控/移动优先折叠