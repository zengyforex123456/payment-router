#!/usr/bin/env node
/**
 * extract-inline-css.js — 自动提取 PHP 内联样式 → CSS class
 *
 * 用法:
 *   node scripts/extract-inline-css.js data/source/views --dry-run    # 仅报告
 *   node scripts/extract-inline-css.js data/source/views --apply      # 执行替换
 *   node scripts/extract-inline-css.js data/source/views --report     # 仅生成报告
 *
 * 策略:
 *   1. 扫描所有 PHP 文件，提取 style="..." 值
 *   2. 精确匹配 → 出现 ≥2 次 → 生成纯 CSS class
 *   3. 模式匹配 → 出现 ≥3 次 → 生成 CSS class + 保留动态差异
 *   4. 替换前备份 → php -l 验证 → 输出迁移报告
 */

var fs = require('fs');
var path = require('path');
var { execSync } = require('child_process');

// ═══ 配置 ═══
var CSS_OUTPUT = null; // 自动探测 skeleton.css
var MIN_EXACT = 2;     // 精确匹配最少出现次数
var MIN_PATTERN = 3;   // 模式匹配最少出现次数

// 已存在的 class 名（避免冲突）
var EXISTING_CLASSES = new Set([
  'card', 'card-header', 'card-title', 'card-body', 'card-footer',
  'btn', 'btn-primary', 'btn-secondary', 'btn-danger',
  'badge', 'badge-success', 'badge-info', 'badge-warning',
  'table', 'table-wrapper', 'desktop-only', 'mobile-only',
  'form-label', 'form-input', 'form-input-mono', 'form-help',
  'form-error', 'form-group', 'form-group-sm',
  'form-grid-2col', 'required', 'code-tag',
  'table-th', 'table-td', 'table-th-right',
  'page-header', 'stat-card', 'stat-value', 'stat-trend-up', 'stat-trend-down',
  'info-bar', 'funnel-section', 'usage-meter', 'quick-action-link',
  'empty-state', 'data-table', 'data-table-th',
  'settings-section-title', 'settings-checkbox-label', 'settings-checkbox',
  'settings-details', 'settings-summary', 'alert', 'alert-success',
  'alert-danger', 'alert-warning', 'alert-error', 'alert-info-box',
  'btn-icon', 'btn-icon-danger', 'btn-icon-warning', 'btn-icon-accent',
  'card-header-detail', 'detail-grid', 'detail-grid-wide',
  'campaign-filter-bar', 'campaign-filter-select', 'campaign-filter-search',
  'pagination-bar', 'settings-tab-link', 'settings-tab-bar',
  'click-search-row', 'toast', 'toast-container',
  'cmd-overlay', 'cmd-palette', 'cmd-input', 'dock-btn', 'panel-link',
  'activity-bar', 'side-panel', 'main-content',
]);

// ═══ 工具函数 ═══

function globPHP(dir, files) {
  files = files || [];
  var entries = fs.readdirSync(dir, { withFileTypes: true });
  entries.forEach(function (e) {
    var full = path.join(dir, e.name);
    if (e.isDirectory() && e.name !== 'node_modules') {
      globPHP(full, files);
    } else if (e.isFile() && e.name.endsWith('.php')) {
      files.push(full);
    }
  });
  return files;
}

/**
 * 从 style="..." 中提取值，处理 PHP 嵌入
 * 返回 [{ value, line, hasPHP }]
 */
function extractStyles(filePath) {
  var content = fs.readFileSync(filePath, 'utf8');
  var lines = content.split('\n');
  var results = [];

  // 匹配 style="..." (处理跨行和 PHP 嵌入)
  var regex = /style\s*=\s*"((?:[^"\\]|\\.)*)"/g;
  var match;
  while ((match = regex.exec(content)) !== null) {
    var value = match[1].trim();
    if (!value) continue;
    // 检测是否含 PHP
    var hasPHP = /<\?[=php]/.test(value);
    var pos = match.index;
    var lineNum = content.substring(0, pos).split('\n').length;
    results.push({
      value: value,
      raw: match[0],
      line: lineNum,
      hasPHP: hasPHP,
    });
  }
  return results;
}

/**
 * 规范化样式值 → 用于模式匹配
 * 替换: px/rem/% 数值 → {N}, 颜色 → {C}, CSS变量 → {V}, 字体 → {F}
 */
function normalizeStyle(value) {
  return value
    .replace(/\d+px/g, '{N}px')
    .replace(/\d+rem/g, '{N}rem')
    .replace(/\d+%/g, '{N}%')
    .replace(/\d+em/g, '{N}em')
    .replace(/#[0-9a-fA-F]{3,8}/g, '{C}')
    .replace(/var\([^)]+\)/g, '{V}')
    .replace(/rgb\([^)]+\)/g, '{C}')
    .replace(/\d+\.?\d*/g, '{N}')
    .replace(/\s+/g, ' ');
}

/**
 * 语义化 class 名生成
 * 优先级: 语义 > 属性名缩写
 */
var SEMANTIC_MAP = {
  // ── 单属性: 间距 ──
  'margin-bottom: 24px': 'mb-24',
  'margin-bottom: 20px': 'mb-20',
  'margin-bottom: 16px': 'mb-16',
  'margin-bottom: 12px': 'mb-12',
  'margin-bottom: 8px': 'mb-8',
  'margin-bottom: 4px': 'mb-4',
  'margin-top: 8px': 'mt-8',
  'margin-top: 4px': 'mt-4',
  'margin-top: 12px': 'mt-12',
  'margin-top: 16px': 'mt-16',
  'margin-top: 24px': 'mt-24',
  'padding: 12px': 'p-12',
  'padding: 16px': 'p-16',
  'padding: 8px': 'p-8',
  'padding: 20px': 'p-20',
  'padding: 10px': 'p-10',

  // ── 单属性: 排版 ──
  'font-size: 12px': 'text-xs',
  'font-size: 11px': 'text-2xs',
  'font-size: 14px': 'text-sm',
  'font-size: 16px': 'text-base',
  'font-size: 18px': 'text-lg',
  'font-size: 13px': 'text-13',
  'font-weight: 600': 'font-semibold',
  'font-weight: 700': 'font-bold',
  'font-weight: 500': 'font-medium',
  'text-align: center': 'text-center',
  'text-align: left': 'text-left',
  'text-align: right': 'text-right',
  'line-height: 1.5': 'leading-relaxed',
  'line-height: 1.6': 'leading-loose',

  // ── 单属性: 颜色 (CSS变量) ──
  'color: var(--content-secondary)': 'text-secondary',
  'color: var(--content-tertiary)': 'text-tertiary',
  'color: var(--content-primary)': 'text-primary',
  'color: var(--success)': 'text-success',
  'color: var(--danger)': 'text-danger',
  'color: var(--warning)': 'text-warning',
  'color: var(--accent-emphasis)': 'text-accent',
  'background: var(--success-soft)': 'bg-success-soft',
  'background: var(--danger-soft)': 'bg-danger-soft',
  'background: var(--warning-soft)': 'bg-warning-soft',
  'background: var(--surface-base)': 'bg-base',
  'background: var(--surface-raised)': 'bg-raised',
  'background: var(--surface-overlay)': 'bg-overlay',

  // ── 单属性: 布局 ──
  'display: block': 'd-block',
  'display: inline': 'd-inline',
  'display: inline-block': 'd-inline-block',
  'display: inline-flex': 'd-inline-flex',
  'width: 100%': 'w-full',
  'max-width: 100%': 'max-w-full',
  'overflow-x: auto': 'overflow-x-auto',
  'overflow-y: auto': 'overflow-y-auto',
  'overflow: hidden': 'overflow-hidden',
  'word-break: break-all': 'break-all',
  'white-space: nowrap': 'whitespace-nowrap',
  'text-transform: uppercase': 'uppercase',
  'cursor: pointer': 'cursor-pointer',
  'border-collapse: collapse': 'border-collapse',

  // ── 单属性: 尺寸 ──
  'width: 20px': 'w-20',
  'height: 20px': 'h-20',
  'width: 24px': 'w-24',
  'height: 24px': 'h-24',
  'width: 36px': 'w-36',
  'height: 36px': 'h-36',

  // ── 多属性: 常见组合 ──
  'display:flex;align-items:center;gap:8px': 'flex-center-gap',
  'display:flex;align-items:center;gap:12px': 'flex-center-gap-lg',
  'display:flex;align-items:center;justify-content:space-between': 'flex-between',
  'display:flex;align-items:center;justify-content:center': 'flex-center',
  'display:flex;align-items:flex-start;gap:12px': 'flex-start-gap',
  'display:flex;gap:6px;flex-wrap:wrap': 'flex-wrap-gap-sm',
  'display:flex;gap:12px': 'flex-gap',
  'display:flex;gap:12px;align-items:center': 'flex-gap-center',
  'display:flex;align-items:center': 'flex-items-center',
  'display:block;font-weight:600;margin-bottom:8px': 'form-label',  // 已有
  'width:100%;padding:10px;border:2px solid var(--border-default);border-radius:4px': 'form-input',  // 已有
};

function semanticClassName(styleValue) {
  // 规范化: 去除首尾空白和分号，统一空格
  var actual = styleValue
    .replace(/;\s*$/, '')
    .replace(/\s*;\s*/g, '; ')
    .replace(/:\s+/g, ': ')
    .trim();

  // 1. 先查精确值映射
  if (SEMANTIC_MAP[actual]) return SEMANTIC_MAP[actual];

  var norm = normalizeStyle(actual);

  // 2. 单属性 → 属性值缩写
  var parts = norm.split(';').map(function (s) { return s.trim(); }).filter(Boolean);
  if (parts.length === 1) {
    var p = parts[0];
    var m = p.match(/^([a-z-]+)\s*:\s*(.+)$/);
    if (m) {
      var prop = m[1], val = m[2];
      // margin-bottom: X → mb-X
      if (prop === 'margin-bottom') return 'mb-' + val.replace(/[^a-zA-Z0-9.-]/g, '');
      if (prop === 'margin-top') return 'mt-' + val.replace(/[^a-zA-Z0-9.-]/g, '');
      if (prop === 'margin-left') return 'ml-' + val.replace(/[^a-zA-Z0-9.-]/g, '');
      if (prop === 'margin-right') return 'mr-' + val.replace(/[^a-zA-Z0-9.-]/g, '');
      if (prop === 'padding') return 'p-' + val.replace(/[^a-zA-Z0-9.-]/g, '');
      if (prop === 'padding-top') return 'pt-' + val.replace(/[^a-zA-Z0-9.-]/g, '');
      if (prop === 'padding-bottom') return 'pb-' + val.replace(/[^a-zA-Z0-9.-]/g, '');
      if (prop === 'font-size') {
        if (val.indexOf('11') !== -1) return 'text-2xs';
        if (val.indexOf('12') !== -1) return 'text-xs';
        if (val.indexOf('13') !== -1) return 'text-13';
        if (val.indexOf('14') !== -1) return 'text-sm';
        if (val.indexOf('16') !== -1) return 'text-base';
        if (val.indexOf('18') !== -1) return 'text-lg';
        return 'text-' + val.replace(/[^a-zA-Z0-9]/g, '');
      }
      if (prop === 'width') return 'w-' + val.replace(/[^a-zA-Z0-9.-]/g, '');
      if (prop === 'height') return 'h-' + val.replace(/[^a-zA-Z0-9.-]/g, '');
      if (prop === 'color') {
        if (val.indexOf('var(--content-secondary)') !== -1) return 'text-secondary';
        if (val.indexOf('var(--content-tertiary)') !== -1) return 'text-tertiary';
        if (val.indexOf('var(--success)') !== -1) return 'text-success';
        if (val.indexOf('var(--danger)') !== -1) return 'text-danger';
        if (val.indexOf('var(--warning)') !== -1) return 'text-warning';
        return 'text-custom';
      }
      if (prop === 'display') {
        if (val === 'block') return 'd-block';
        if (val === 'inline') return 'd-inline';
        if (val === 'inline-block') return 'd-inline-block';
        if (val === 'inline-flex') return 'd-inline-flex';
        return 'd-' + val;
      }
      return prop.substring(0, 2) + '-' + val.replace(/[^a-zA-Z0-9.-]/g, '');
    }
  }

  // 3.5: 检查是否含占位符 → 特殊处理
  var hasDynamic = /{N}|{C}|{V}|{F}/.test(norm);
  if (hasDynamic && parts.length === 1) {
    var dm = parts[0].match(/^([a-z-]+)\s*:\s*(.+)$/);
    if (dm) {
      var dprop = dm[1];
      // 只用属性名生成 class，值动态变化
      var propMap = {
        'max-width': 'max-w', 'min-width': 'min-w', 'max-height': 'max-h',
        'margin-left': 'ml-auto', 'margin-right': 'mr-auto',
        'padding-left': 'pl-auto', 'padding-right': 'pr-auto',
        'border-color': 'border-c', 'background-color': 'bg-c',
        'font-family': 'font-mono', 'gap': 'gap-auto',
      };
      if (propMap[dprop]) return propMap[dprop];
      return dprop.replace(/[^a-z-]/g, '');
    }
  }

  // 4. 多属性 → 语义模式
  var normNoSpace = norm.replace(/\s+/g, '');
  if (normNoSpace.indexOf('display:flex') !== -1 && normNoSpace.indexOf('align-items:center') !== -1 && normNoSpace.indexOf('justify-content:space-between') !== -1) return 'flex-between';
  if (normNoSpace.indexOf('display:flex') !== -1 && normNoSpace.indexOf('align-items:center') !== -1) return 'flex-center-row';
  if (normNoSpace.indexOf('display:flex') !== -1 && normNoSpace.indexOf('flex-wrap:wrap') !== -1) return 'flex-wrap';
  if (normNoSpace.indexOf('display:flex') !== -1) return 'flex-row';
  if (normNoSpace.indexOf('display:grid') !== -1 && normNoSpace.indexOf('1fr1fr') !== -1) return 'grid-2col';
  if (normNoSpace.indexOf('display:grid') !== -1) return 'grid-cols';
  if (normNoSpace.indexOf('text-align:center') !== -1 && normNoSpace.indexOf('padding') !== -1) return 'table-cell-center';

  return null;
}

// ═══ 主流程 ═══

function collectStyles(viewsDir) {
  var files = globPHP(viewsDir);
  console.log('📂 扫描 ' + files.length + ' 个 PHP 文件...\n');

  var allStyles = [];
  var fileStyles = {};

  files.forEach(function (f) {
    var rel = path.relative(viewsDir, f);
    var styles = extractStyles(f);
    if (styles.length > 0) {
      fileStyles[rel] = styles;
      styles.forEach(function (s) {
        s.file = rel;
        allStyles.push(s);
      });
    }
  });

  console.log('   ' + allStyles.length + ' 个内联样式');
  console.log('   ' + Object.keys(fileStyles).length + ' 个文件含内联样式\n');

  return { all: allStyles, byFile: fileStyles, files: files };
}

function analyzeStyles(allStyles) {
  // 1. 精确匹配分组
  var exactGroups = {};
  allStyles.forEach(function (s) {
    if (s.hasPHP) return; // 跳过含 PHP 的动态样式
    var key = s.value;
    if (!exactGroups[key]) exactGroups[key] = [];
    exactGroups[key].push(s);
  });

  // 2. 模式匹配分组
  var patternGroups = {};
  allStyles.forEach(function (s) {
    if (s.hasPHP) return;
    var key = normalizeStyle(s.value);
    if (!patternGroups[key]) patternGroups[key] = [];
    patternGroups[key].push(s);
  });

  // 3. 筛选: 精确匹配 ≥ MIN_EXACT
  var exactHits = {};
  Object.entries(exactGroups).forEach(function (_a) {
    var val = _a[0], items = _a[1];
    if (items.length >= MIN_EXACT) {
      exactHits[val] = items;
    }
  });

  // 4. 筛选: 模式匹配 ≥ MIN_PATTERN（排除已被精确覆盖的）
  var patternHits = {};
  Object.entries(patternGroups).forEach(function (_a) {
    var norm = _a[0], items = _a[1];
    if (items.length >= MIN_PATTERN && !exactHits[items[0].value]) {
      // 检查是否所有实例的模式一致
      var uniqueValues = new Set(items.map(function (s) { return s.value; }));
      if (uniqueValues.size >= 2) {
        patternHits[norm] = items;
      }
    }
  });

  var totalExactReplacements = Object.values(exactHits).reduce(function (s, a) { return s + a.length; }, 0);
  var totalPatternReplacements = Object.values(patternHits).reduce(function (s, a) { return s + a.length; }, 0);

  console.log('📊 分析结果:');
  console.log('   精确匹配可替换: ' + totalExactReplacements + ' 处 (' + Object.keys(exactHits).length + ' 模式)');
  console.log('   模式匹配可替换: ' + totalPatternReplacements + ' 处 (' + Object.keys(patternHits).length + ' 模式)');
  console.log('   合计可消除:     ' + (totalExactReplacements + totalPatternReplacements) + ' 处\n');

  return { exactHits: exactHits, patternHits: patternHits, total: totalExactReplacements + totalPatternReplacements };
}

function generateMappings(exactHits, patternHits) {
  var mappings = [];
  var cssRules = [];
  var classCounter = 0;

  // 处理精确匹配
  Object.entries(exactHits).forEach(function (_a) {
    var val = _a[0], items = _a[1];
    var name = semanticClassName(val);
    if (!name) {
      name = 'iu-' + (++classCounter);
    }
    // 去重 + 验证格式
    name = name.replace(/[^a-zA-Z0-9_-]/g, '-');
    // 跳过含占位符的无效名
    if (/[{}]/.test(name)) name = 'iu-exact-' + (++classCounter);
    if (EXISTING_CLASSES.has(name)) name = name + '-x' + (++classCounter);

    var cssProp = val
      .split(';')
      .map(function (s) { return s.trim(); })
      .filter(Boolean)
      .map(function (s) { return '    ' + s + ';'; })
      .join('\n');

    mappings.push({
      oldStyle: val,
      className: name,
      css: '.' + name + ' {\n' + cssProp + '\n}',
      occurrences: items.length,
      isPattern: false,
      dynamic: false,
    });
    cssRules.push('.' + name + ' {\n' + cssProp + '\n}');
  });

  // 处理模式匹配
  Object.entries(patternHits).forEach(function (_a) {
    var norm = _a[0], items = _a[1];
    var name = semanticClassName(items[0].value);
    if (!name) {
      name = 'iu-p' + (++classCounter);
    } else {
      name = name + '-p';
    }
    if (EXISTING_CLASSES.has(name) || mappings.some(function (m) { return m.className === name; })) name = name + '-' + (++classCounter);

    // 取第一个实例作为 CSS 模板，提取公共部分
    var sample = items[0].value;
    var sampleNorm = normalizeStyle(sample);
    var sampleParts = sample.split(';').map(function (s) { return s.trim(); }).filter(Boolean);

    // 找出同类项中的公共属性
    var allValues = items.map(function (s) { return s.value; });
    var commonParts = sampleParts.filter(function (part) {
      var pNorm = normalizeStyle(part);
      return allValues.every(function (v) {
        return v.split(';').map(function (s) { return s.trim(); }).some(function (vp) {
          return normalizeStyle(vp) === pNorm;
        });
      });
    });

    if (commonParts.length === 0) commonParts = [sampleParts[0]];

    var cssProp = commonParts
      .map(function (s) { return '    ' + s + ';'; })
      .join('\n');

    mappings.push({
      oldStyle: norm,
      className: name,
      css: '.' + name + ' {\n' + cssProp + '\n}',
      occurrences: items.length,
      isPattern: true,
      dynamic: true,
      // 对每个实例，提取差异部分
      instances: items.map(function (s) {
        var remaining = s.value
          .split(';')
          .map(function (p) { return p.trim(); })
          .filter(function (p) {
            return !commonParts.some(function (cp) {
              return normalizeStyle(p) === normalizeStyle(cp);
            });
          })
          .join('; ');
        return {
          file: s.file,
          line: s.line,
          original: s.value,
          remaining: remaining,
        };
      }),
    });
    cssRules.push('.' + name + ' {\n' + cssProp + '\n}');
  });

  console.log('🎨 生成 ' + mappings.length + ' 个 CSS class\n');

  return { mappings: mappings, cssRules: cssRules };
}

function applyReplacements(viewsDir, mappings, dryRun) {
  var files = globPHP(viewsDir);
  var stats = { replaced: 0, skipped: 0, errors: [] };
  var fileChanges = {};

  // 先构建精确映射表（精确匹配优先）
  var exactMap = {};
  mappings
    .filter(function (m) { return !m.isPattern; })
    .forEach(function (m) {
      exactMap[m.oldStyle] = m.className;
    });

  files.forEach(function (filePath) {
    var content = fs.readFileSync(filePath, 'utf8');
    var original = content;
    var replacedInFile = 0;

    // 1. 精确替换
    Object.entries(exactMap).forEach(function (_a) {
      var style = _a[0], cls = _a[1];
      var search = 'style="' + style + '"';
      var replace = 'class="' + cls + '"';
      if (content.indexOf(search) !== -1) {
        var before = content;
        content = content.split(search).join(replace);
        var diff = (before.match(new RegExp(search.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g')) || []).length;
        replacedInFile += diff;
      }
    });

    // 2. 模式替换（保留差异）
    mappings
      .filter(function (m) { return m.isPattern; })
      .forEach(function (m) {
        if (!m.instances) return;
        m.instances
          .filter(function (inst) { return inst.file === path.relative(viewsDir, filePath); })
          .forEach(function (inst) {
            var search = 'style="' + inst.original + '"';
            var newStyle = inst.remaining ? 'class="' + m.className + '" style="' + inst.remaining + '"' : 'class="' + m.className + '"';
            if (content.indexOf(search) !== -1) {
              content = content.split(search).join(newStyle);
              replacedInFile++;
            }
          });
      });

    // 3. 后处理: 合并重复 class= 属性
    var dupePattern = /class="([^"]+)"\s+class="([^"]+)"/g;
    var dupeCount = 0;
    while (dupePattern.test(content)) dupeCount++;
    content = content.replace(/class="([^"]+)"\s+class="([^"]+)"/g, 'class="$1 $2"');
    // 处理三种顺序: class="A" ... class="B" or class="B" ... class="A"
    // 已经在循环中 — 每次替换一对重复的class

    if (replacedInFile > 0 || dupeCount > 0) {
      var rel = path.relative(viewsDir, filePath);
      fileChanges[rel] = { replaced: replacedInFile, dupeMerged: dupeCount, content: content };
      stats.replaced += replacedInFile;
      stats.dupeMerged = (stats.dupeMerged || 0) + dupeCount;
    }
  });

  if (stats.dupeMerged > 0) {
    console.log('   🔧 合并重复 class= : ' + stats.dupeMerged + ' 处');
  }

  if (!dryRun) {
    // 备份
    var backupDir = path.join(viewsDir, '..', '.backup-inline-css');
    if (!fs.existsSync(backupDir)) fs.mkdirSync(backupDir, { recursive: true });

    Object.entries(fileChanges).forEach(function (_a) {
      var rel = _a[0], change = _a[1];
      var filePath = path.join(viewsDir, rel);
      // 备份
      var backupPath = path.join(backupDir, rel.replace(/[\/\\]/g, '_'));
      if (!fs.existsSync(path.dirname(backupPath))) fs.mkdirSync(path.dirname(backupPath), { recursive: true });
      fs.copyFileSync(filePath, backupPath);
      // 写入
      fs.writeFileSync(filePath, change.content, 'utf8');
    });

    console.log('💾 备份至: ' + backupDir);
  }

  console.log('✏️  替换: ' + stats.replaced + ' 处 (' + Object.keys(fileChanges).length + ' 文件)');
  if (dryRun) console.log('   (dry-run: 未实际修改文件)');

  return { stats: stats, fileChanges: fileChanges };
}

function generateCSS(cssRules, cssFile) {
  var header = [
    '/* ═══════════════════════════════════════════════════════',
    '   自动提取自内联样式 — scripts/extract-inline-css.js',
    '   生成时间: ' + new Date().toISOString(),
    '   ═══════════════════════════════════════════════════════ */',
    '',
  ].join('\n');

  var content = header + cssRules.join('\n\n') + '\n';

  // 追加到现有 CSS
  var existing = '';
  if (fs.existsSync(cssFile)) {
    existing = fs.readFileSync(cssFile, 'utf8');
  }
  var marker = '/* ═══ AUTO-EXTRACTED ═══ */';
  if (existing.indexOf(marker) === -1) {
    existing += '\n' + marker + '\n' + content + '\n';
  }

  return { content: content, fullCSS: existing, marker: marker };
}

function validateFiles(viewsDir) {
  var files = globPHP(viewsDir);
  var results = { pass: 0, fail: 0, errors: [] };

  files.forEach(function (f) {
    try {
      var out = execSync('php -l "' + f + '" 2>&1', { encoding: 'utf8', timeout: 5000 });
      if (out.indexOf('No syntax errors') !== -1) {
        results.pass++;
      } else {
        results.fail++;
        results.errors.push({ file: f, error: out.trim() });
      }
    } catch (e) {
      results.fail++;
      results.errors.push({ file: f, error: (e.stdout || e.message || '').toString().trim() });
    }
  });

  return results;
}

// ═══ MAIN ═══

function main() {
  var args = process.argv.slice(2);
  var viewsDir = args[0];
  var dryRun = args.includes('--dry-run') || !args.includes('--apply');
  var reportOnly = args.includes('--report');

  if (!viewsDir) {
    console.error('用法: node scripts/extract-inline-css.js <views-dir> [--dry-run|--apply|--report]');
    process.exit(1);
  }

  if (!fs.existsSync(viewsDir)) {
    console.error('❌ 目录不存在: ' + viewsDir);
    process.exit(1);
  }

  // 自动探测 skeleton.css
  var possibleCSS = [
    path.join(viewsDir, '..', 'public', 'assets', 'css', 'skeleton.css'),
    path.join(viewsDir, '..', 'public', 'css', 'skeleton.css'),
    path.join(viewsDir, '..', 'assets', 'css', 'skeleton.css'),
  ];
  CSS_OUTPUT = possibleCSS.find(function (p) { return fs.existsSync(p); });
  if (!CSS_OUTPUT && !dryRun && !reportOnly) {
    console.error('⚠ 未找到 skeleton.css，将仅输出 CSS 到 stdout');
  }

  console.log('╔═══════════════════════════════════════════════╗');
  console.log('║  extract-inline-css — PHP 内联样式自动迁移  ║');
  console.log('╚═══════════════════════════════════════════════╝');
  console.log('目录: ' + viewsDir);
  console.log('模式: ' + (dryRun ? '--dry-run (仅报告)' : reportOnly ? '--report (仅报告)' : '--apply (执行替换)'));
  console.log();

  // Step 1: 收集
  var collected = collectStyles(viewsDir);

  if (reportOnly) {
    // 仅输出每个文件的内联样式统计
    console.log('═══ 文件内联样式统计 ═══\n');
    Object.entries(collected.byFile)
      .sort(function (a, b) { return b[1].length - a[1].length; })
      .forEach(function (_a) {
        var file = _a[0], styles = _a[1];
        console.log(file + ': ' + styles.length + ' inline');
      });
    process.exit(0);
  }

  // Step 2: 分析
  var analysis = analyzeStyles(collected.all);

  if (analysis.total === 0) {
    console.log('✅ 无可优化的内联样式。');
    process.exit(0);
  }

  // Step 3: 生成映射
  var gen = generateMappings(analysis.exactHits, analysis.patternHits);

  // 打印最有效的替换
  console.log('═══ Top 20 替换 (按出现次数) ═══\n');
  gen.mappings
    .sort(function (a, b) { return b.occurrences - a.occurrences; })
    .slice(0, 20)
    .forEach(function (m, i) {
      console.log((i + 1) + '. .' + m.className + '  (' + m.occurrences + 'x)');
      console.log('   ' + (m.css.split('\n')[1] || '').trim());
      console.log();
    });

  // Step 4: 应用替换
  var result = applyReplacements(viewsDir, gen.mappings, dryRun);

  // Step 5: 验证
  if (!dryRun) {
    console.log('\n🔍 验证 PHP 语法...');
    var valid = validateFiles(viewsDir);
    console.log('   ✅ ' + valid.pass + ' 通过  ❌ ' + valid.fail + ' 失败');
    if (valid.fail > 0) {
      console.log('\n⚠ 语法错误文件:');
      valid.errors.forEach(function (e) {
        console.log('   - ' + path.basename(e.file) + ': ' + e.error.split('\n')[0]);
      });
      console.log('\n⚠ 自动回滚建议: 从 .backup-inline-css/ 恢复');
    }
  }

  // Step 6: 输出 CSS
  if (!dryRun && CSS_OUTPUT && gen.cssRules.length > 0) {
    var css = generateCSS(gen.cssRules, CSS_OUTPUT);
    fs.writeFileSync(CSS_OUTPUT, css.fullCSS, 'utf8');
    console.log('\n📝 CSS 已追加至: ' + CSS_OUTPUT + ' (' + gen.cssRules.length + ' 条规则)');
  } else if (dryRun && gen.cssRules.length > 0) {
    console.log('\n📝 预览 CSS (将追加到 ' + (CSS_OUTPUT || 'skeleton.css') + '):');
    console.log(gen.cssRules.slice(0, 10).join('\n\n'));
    if (gen.cssRules.length > 10) console.log('   ... (' + (gen.cssRules.length - 10) + ' more rules)');
  }

  // Step 7: 总结
  console.log('\n═══ 迁移总结 ═══');
  console.log('初始内联样式:  ' + collected.all.length);
  console.log('预计消除:      ' + analysis.total + ' (' + Math.round(analysis.total / collected.all.length * 100) + '%)');
  console.log('预计剩余:      ' + (collected.all.length - analysis.total));
  console.log('CSS class:     ' + gen.cssRules.length + ' 条规则');
}

main();
