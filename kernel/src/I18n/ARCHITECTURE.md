# Converge I18n — 4层可复用架构

> 版本: v2.0 | 单一真源 · 杜绝硬编码 · 可跨项目复用
> 阶段2重构: 废弃 Core\I18n 双系统，统一到 Locale

## 设计原则

**一套 key 单一真源，所有文字从翻译文件出，杜绝硬编码。** 根治历史7个双语bug（双系统冲突/URL丢参/JS硬编码/双布局不同步）。

## 4层结构（单向依赖 L4→L3→L2→L1）

```
L1 数据源      lang/{en,zh}.php (1229 key 单一真源) + 本文档(key命名规范)
                  ↑ 只读
L2 核心解析    src/I18n/Locale.php
                  ::detect()   语言检测 URL>Session>Cookie>Accept-Language
                  ::translate() key→文本 + 回退链(key→default→key本身)
                  ::lang()/::all()
                  ↑ 调用
L3 注入绑定    src/I18n/helpers.php  __() / lang() 全局函数(composer autoload files)
                  Locale::injectJS()  → window.I18N (前端注入)
                  ↑ 验证
L4 验证        checks/i18n-compliance.php  硬编码扫描 + key一致性 + 缺失键(--fix-keys)
                  src/Verification/LanguageConsistencyChecker.php  运行时一致性
```

**为何 Locale 不物理拆分**：130行内聚单元，detect/translate/injectJS 总是一起变更（同一变更理由），拆分会增文件数无实益。符合 SRP"变更理由唯一"原则。

## 层职责边界

| 层 | 文件 | 职责 | 禁止 |
|----|------|------|------|
| L1 | lang/*.php | 只存 key-value | 不含逻辑 |
| L2 | Locale.php | 检测+解析+回退 | 不碰HTTP响应/不注入HTML |
| L3 | helpers.php | 全局函数 + JS注入 | 不直接读文件(经L2) |
| L4 | checks/ + Verification/ | 验证合规 | 不修改L1-L3 |

## key 命名规范

```
简单key:  login, username, dashboard        (页面级短文本)
点分key:  dash.revenue, campaign.form.name   (模块.子模块.描述, 深度≤3)
规则: 小写+下划线, 第一段为模块名, 避免数字索引
```

## 复用到其他项目

3个文件即可移植: `Locale.php`(L2) + `helpers.php`(L3) + `checks/i18n-compliance.php`(L4) + 建 `lang/{en,zh}.php`(L1)。composer autoload 加 `"files": ["src/I18n/helpers.php"]`。

## 用法

```php
// 后端
Locale::init();              // 入口顶部调用一次
echo __('login');            // 取翻译
$lang = lang();              // 当前语言

// 前端注入
echo Locale::injectJS(['login','dashboard']);  // <script>window.I18N={...}</script>
```

## CI 门禁

```bash
php checks/i18n-compliance.php --json   # pass:true 才过
php checks/i18n-compliance.php --fix-keys  # 自动补缺失key
```
遗留: 132处存量硬编码(views/campaigns.php等)待"杜绝硬编码"专项清理。
