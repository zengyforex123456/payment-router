---
name: converge-debranding-5layers
description: "Converge去身份化5层补全 — 阶段1只改命名空间,本轮补L2-L5品牌残留+门禁"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: a6bb6b70-ca5c-47ca-9426-25f8f3551d1e
---

# Converge 去身份化 5 层补全 (2026-07-11)

## 检测模式
线上登录页还是开源品牌(Simple KUMA)/logo旧/主题key旧

## 根因
"改名"含5层,阶段1只做了L1命名空间,L2-L5遗漏:
- L1 命名空间 SimpleKuma\→Converge\ (阶段1已做)
- L2 文件注释 版权/描述含KUMA (58处遗漏)
- L3 UI文案 "Simple KUMA"登录页/标题 (85处遗漏)
- L4 主题key kuma_theme localStorage (4处遗漏)
- L5 Logo mainlogo.png旧品牌图 (9处遗漏)

## 修复
- 批量替换: Simple KUMA→Converge(57文件197处) + mainlogo.png→logo.svg + kuma_theme→converge_theme(全站配对)
- 登录页: login.php重定向到login-v2(阶段2已做的Converge品牌页,内嵌SVG logo)
- 门禁: brand-check.cjs(黑白名单,防回归)

## 关键区分(避免误伤)
- **KumaHop/kumahop**: 功能名(referrer隐私),**保留**,加白名单
- **kuma_click_id**: 前后端存读配对的sessionStorage键,改需一致性验证,**本轮保留**(内部键不影响品牌观感)
- 只安全改: 品牌文案/注释/logo/kuma_theme

## 教训
去身份化是**多层**工程,只改命名空间会留残留。改名前必须全surface审计(rg扫品牌名),分类处理(文案/注释/key/logo),加门禁防回归。参考 [[oss-secondary-dev-guide]] 的5层模型。

## 验证
brand-check扫457文件0品牌残留。全站语法检查通过。

关联: [[converge-server-deploy-2026]] [[ccgav-seven-cap-standard]]
