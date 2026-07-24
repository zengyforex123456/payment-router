# I18n 统一语言层 — 可复用套件 v1.0

> 适用: 任何 PHP 8.2+ 项目 | 零依赖 | 5 分钟接入

## 套件文件清单

```
src/I18n/
  Locale.php          ← 语言引擎 (复制即用, 不改)
  helpers.php         ← __() 函数 (复制即用, 不改)
  README.md           ← 本文件

lang/
  zh.php              ← 翻译文件模板 (项目定制)
  en.php              ← 翻译文件模板 (项目定制)

scripts/
  i18n-migrate-views.php  ← 批量迁移脚本 (项目定制)

composer.json 补丁     ← 加一行 files autoload
```

## 新项目接入 (5 步)

### Step 1: 复制文件

```bash
# 从 Converge 复制核心文件
cp src/I18n/Locale.php   <新项目>/src/I18n/Locale.php
cp src/I18n/helpers.php  <新项目>/src/I18n/helpers.php
mkdir -p <新项目>/lang
```

### Step 2: 注册 Composer autoload

在 `composer.json` 的 `autoload` 段加:

```json
"autoload": {
    "psr-4": { "YourNamespace\\": "src/" },
    "files": ["src/I18n/helpers.php"]
}
```

然后:

```bash
composer dump-autoload
```

或手动编辑 `vendor/composer/autoload_files.php` 和 `autoload_static.php`。

### Step 3: 创建翻译文件

复制 `lang/en.php` 和 `lang/zh.php` 模板, 填入项目自己的键:

```php
// lang/zh.php
return [
    'common.save' => '保存',
    'common.cancel' => '取消',
    // ... 你的项目键
];

// lang/en.php
return [
    'common.save' => 'Save',
    'common.cancel' => 'Cancel',
    // ... your project keys
];
```

### Step 4: 入口文件初始化

在 `index.php` (或任何入口) 最顶部加:

```php
require_once __DIR__ . '/../vendor/autoload.php';
\Converge\I18n\Locale::init();  // 必须在任何输出之前
```

> 如果 namespace 不同, 改 `helpers.php` 中的类引用

### Step 5: 替换硬编码

逐个 View 替换:

```php
// 旧
<h1>Campaigns</h1>
<button>Save</button>

// 新
<h1><?=__('campaign.title')?></h1>
<button><?=__('common.save')?></button>
```

或用迁移脚本批量处理 (见下方)。

## JS 端使用

在 layout 文件中注入:

```php
<?=\Converge\I18n\Locale::injectJS(['common.save', 'common.cancel'])?>
```

JS 中:

```js
function t(key) { return (window.I18N && window.I18N.t[key]) || key; }
t('common.save');  // → 保存 / Save
```

## 迁移脚本用法

```bash
# 预览 (不改文件)
php scripts/i18n-migrate-views.php

# 应用
php scripts/i18n-migrate-views.php --apply
```

修改 `$replacements` 数组, 添加项目自己的映射。

## 架构原则

```
一次检测, 一处存储, 全站共用:

  Locale::init()         ← 语言检测 (URL > Session > Cookie > Accept-Language)
       ↓
  lang/{zh,en}.php      ← 全部翻译的唯二来源 (400+ 键)
       ↓
  __('sidebar.tracking') ← PHP 端统一调用
  window.I18N.t['key']   ← JS 端从 PHP 注入
```

## 禁止模式

| ❌ | ✅ |
|----|----|
| 硬编码中英文 | `__('key')` |
| 每文件自己维护翻译数组 | 统一在 lang/ 文件 |
| 语言检测写在 v2.php 里 | `Locale::init()` 一次 |
| JS 自己翻译 | `window.I18N` 从 PHP 注入 |
