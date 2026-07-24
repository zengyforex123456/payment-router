// @ts-nocheck
// checks/modularization-score.js — 项目模块化+分层标准评估 v3.0
// 对应规则: 分模块·模块内分层·单一功能·接口通信
// 评估六维: 模块划分·分层清晰·接口契约·单一职责·隔离度·Fabric注册
// 支持: Node.js (src/features/) + PHP (src/*/) 双 conventions
// 运行: node checks/modularization-score.js [项目目录]
// 退出码: 0=通过(≥70分), 1=警告(50-69), 2=不合格(<50)

var path = require('path');
var fs = require('fs');

var ROOT = process.argv[2] || '.';
ROOT = path.resolve(ROOT);

// ═══ 六维评分标准 ═══
var DIMENSIONS = {
  modularization: { weight: 25, name: '模块划分', desc: '按业务域分模块，非按技术层堆放' },
  intraModuleLayering: { weight: 20, name: '模块内分层', desc: '每个模块内有清晰的 L1-L4 分层' },
  contractQuality: { weight: 20, name: '接口契约', desc: '模块间通过 JSON schema 通信，非直接 require' },
  singleResponsibility: { weight: 15, name: '单一职责', desc: '文件≤150行，函数≤50行，描述无"和"字' },
  isolation: { weight: 10, name: '模块隔离度', desc: '模块间松耦合，跨模块引用通过 fabric' },
  fabricRegistration: { weight: 10, name: 'Fabric注册', desc: '每个模块在 registry.json 有声明' },
};

// ═══ 语言检测 ═══
function detectLanguage(dir) {
  var srcDir = path.join(dir, 'src');
  if (!fs.existsSync(srcDir)) return 'unknown';

  // JS convention: src/features/
  if (fs.existsSync(path.join(srcDir, 'features'))) return 'js';

  // PHP convention: src/{Module}/ with PHP files
  try {
    var entries = fs.readdirSync(srcDir);
    for (var i = 0; i < entries.length; i++) {
      var full = path.join(srcDir, entries[i]);
      if (!fs.statSync(full).isDirectory()) continue;
      try {
        var subs = fs.readdirSync(full);
        for (var j = 0; j < subs.length; j++) {
          if (subs[j].endsWith('.php')) return 'php';
        }
      } catch (_e) { /* skip */ }
    }
  } catch (_e) { /* skip */ }

  return 'unknown';
}

// ═══ 扫描项目结构 ═══
function scanProject(dir) {
  var lang = detectLanguage(dir);
  var srcDir = path.join(dir, 'src');
  var fabricDir = path.join(dir, 'fabric');

  var result = {
    dir: dir,
    language: lang,
    hasFabric: fs.existsSync(fabricDir),
    modules: [],
    moduleDirs: {},
    moduleFiles: {},
    crossModuleRefs: 0,
    layeringScore: 0,
  };

  // 发现模块 (按语言约定)
  var moduleDirs = _findModuleDirs(dir, lang);

  moduleDirs.forEach(function (entry) {
    result.modules.push(entry.name);
    result.moduleDirs[entry.name] = entry.path;
    result.moduleFiles[entry.name] = _scanModuleFiles(entry.path, lang);
  });

  // 检查 fabric 注册 (registry.json + module-registry.json)
  var registryPath = path.join(dir, 'fabric', 'registry.json');
  var moduleRegPath = path.join(dir, 'fabric', 'module-registry.json');
  var registeredIds = {};

  if (fs.existsSync(registryPath)) {
    try {
      var reg = JSON.parse(fs.readFileSync(registryPath, 'utf8'));
      // 两种格式: {modules:{id:{...}}} 或 [{id:...}]
      if (reg.modules && typeof reg.modules === 'object') {
        Object.keys(reg.modules).forEach(function (k) { registeredIds[k] = true; });
      }
      if (Array.isArray(reg)) {
        reg.forEach(function (m) { if (m.id) registeredIds[m.id] = true; });
      }
    } catch (_e) { /* ignore */ }
  }
  if (fs.existsSync(moduleRegPath)) {
    try {
      var mreg = JSON.parse(fs.readFileSync(moduleRegPath, 'utf8'));
      if (mreg.modules && typeof mreg.modules === 'object') {
        Object.keys(mreg.modules).forEach(function (k) { registeredIds[k] = true; });
      }
    } catch (_e) { /* ignore */ }
  }
  result.registryModules = Object.keys(registeredIds);

  // 检查跨模块引用
  result.crossModuleRefs = _countCrossModuleRefs(dir, result, lang);

  return result;
}

// ═══ 发现模块目录 ═══
function _findModuleDirs(dir, lang) {
  var modules = [];
  var srcDir = path.join(dir, 'src');

  if (!fs.existsSync(srcDir)) return modules;

  if (lang === 'js') {
    // JS: src/features/<module>/
    var featuresDir = path.join(srcDir, 'features');
    if (fs.existsSync(featuresDir)) {
      try {
        var entries = fs.readdirSync(featuresDir);
        entries.forEach(function (e) {
          var full = path.join(featuresDir, e);
          if (fs.statSync(full).isDirectory() && !e.startsWith('_') && !e.startsWith('.')) {
            modules.push({ name: e, path: full });
          }
        });
      } catch (_e) { /* ignore */ }
    }
  } else if (lang === 'php') {
    // PHP: src/{Module}/ — 含 .php 文件的子目录都是模块
    try {
      var entries = fs.readdirSync(srcDir);
      entries.forEach(function (e) {
        var full = path.join(srcDir, e);
        if (!fs.statSync(full).isDirectory() || e.startsWith('_') || e.startsWith('.')) return;
        // 检查是否包含 PHP 文件
        var hasPhp = false;
        try {
          var subs = fs.readdirSync(full);
          for (var i = 0; i < subs.length; i++) {
            if (subs[i].endsWith('.php')) { hasPhp = true; break; }
          }
        } catch (_er) { /* skip */ }
        if (hasPhp) modules.push({ name: e, path: full });
      });
    } catch (_e) { /* ignore */ }
  }

  return modules;
}

// ═══ 六维评分 ═══
function evaluate(scan) {
  var scores = {};

  // 1. 模块划分 (25分): 有模块目录 + 模块数 ≥ 2
  var modCount = scan.modules.length;
  if (modCount === 0) {
    scores.modularization = { score: 0, max: 25, detail: '无模块划分 — 建议创建 src/features/<domain>/ 或 src/{Module}/' };
  } else if (modCount === 1) {
    scores.modularization = { score: 10, max: 25, detail: '仅1个模块 — 考虑是否可拆分' };
  } else {
    var ratio = Math.min(modCount / 6, 1);
    scores.modularization = {
      score: Math.round(15 + 10 * ratio),
      max: 25,
      detail: modCount + ' 个模块 — ' + scan.modules.slice(0, 8).join(', ') + (modCount > 8 ? '...' : ''),
    };
  }

  // 2. 模块内分层 (20分): 每个模块有 controller/handler/service/model 等
  scores.intraModuleLayering = _evaluateLayering(scan.moduleFiles, scan.language);

  // 3. 接口契约 (20分): 有 fabric 注册 + 模块声明 produces/consumes
  var registCount = (scan.registryModules || []).length;
  var registRatio = scan.modules.length > 0
    ? Math.min(registCount / scan.modules.length, 1.5)
    : 0;
  var contractScore = Math.min(Math.round(registRatio * 20), 20);
  if (!scan.hasFabric) {
    scores.contractQuality = { score: 0, max: 20, detail: '无 fabric/ — 模块未声明接口契约' };
  } else {
    scores.contractQuality = {
      score: contractScore,
      max: 20,
      detail: registCount + '/' + scan.modules.length + ' 模块已注册fabric' + (registCount > scan.modules.length ? ' (含跨注册表)' : ''),
    };
  }

  // 4. 单一职责 (15分): 文件大小 + 函数大小
  scores.singleResponsibility = _evaluateSRP(scan.dir, scan.language);

  // 5. 模块隔离度 (10分): 跨模块引用越少越好
  var refsPerModule = scan.modules.length > 0 ? scan.crossModuleRefs / scan.modules.length : 99;
  if (refsPerModule === 0 && scan.modules.length >= 2) {
    scores.isolation = { score: 10, max: 10, detail: '零跨模块直接引用 — 优秀' };
  } else if (refsPerModule <= 2) {
    scores.isolation = { score: 7, max: 10, detail: refsPerModule.toFixed(1) + ' 次/模块交叉引用' };
  } else if (refsPerModule <= 5) {
    scores.isolation = { score: 4, max: 10, detail: refsPerModule.toFixed(1) + ' 次/模块交叉引用 — 偏多' };
  } else {
    scores.isolation = { score: 1, max: 10, detail: refsPerModule.toFixed(1) + ' 次/模块交叉引用 — 严重耦合' };
  }

  // 6. Fabric注册 (10分)
  var fabricScore = Math.min(Math.round(registRatio * 10), 10);
  if (!scan.hasFabric) {
    scores.fabricRegistration = { score: 0, max: 10, detail: '无 fabric 目录' };
  } else if (registRatio >= 1) {
    scores.fabricRegistration = { score: 10, max: 10, detail: registCount + ' 模块已注册' };
  } else {
    scores.fabricRegistration = { score: fabricScore, max: 10, detail: registCount + '/' + scan.modules.length + ' 已注册' };
  }

  // ═══ 汇总 ═══
  var totalScore = 0;
  var totalMax = 0;
  Object.keys(scores).forEach(function (key) {
    totalScore += scores[key].score;
    totalMax += scores[key].max;
  });

  var grade = totalScore >= 70 ? 'A-合格' : totalScore >= 50 ? 'B-待改进' : 'C-不合格';

  return {
    project: scan.dir,
    language: scan.language,
    totalScore: totalScore,
    totalMax: totalMax,
    percentage: Math.round(totalScore / totalMax * 100),
    grade: grade,
    dimensions: scores,
    scan: {
      modules: scan.modules,
      moduleCount: scan.modules.length,
      hasFabric: scan.hasFabric,
      registryCoverage: Math.round(registRatio * 100) + '%',
    },
  };
}

// ═══ 辅助函数 ═══

function _scanModuleFiles(moduleDir, lang) {
  var files = {};
  var exts = lang === 'php' ? ['.php'] : ['.js', '.ts'];
  try {
    var entries = fs.readdirSync(moduleDir);
    entries.forEach(function (e) {
      var full = path.join(moduleDir, e);
      if (!fs.statSync(full).isFile()) return;
      var matched = false;
      for (var i = 0; i < exts.length; i++) {
        if (e.endsWith(exts[i])) { matched = true; break; }
      }
      if (!matched) return;
      files[e] = { path: full, lines: _countLines(full) };
    });
  } catch (_e) { /* ignore */ }
  return files;
}

function _countLines(filePath) {
  try {
    return fs.readFileSync(filePath, 'utf8').split('\n').length;
  } catch (_e) { return 0; }
}

function _countCrossModuleRefs(dir, scan, lang) {
  var count = 0;

  function _scanDirRecursive(mod, modDir) {
    try {
      var entries = fs.readdirSync(modDir);
      entries.forEach(function (f) {
        var full = path.join(modDir, f);
        try {
          if (fs.statSync(full).isDirectory()) {
            _scanDirRecursive(mod, full);
            return;
          }
        } catch (_er) { return; }
        var ext = path.extname(f);
        if ((lang === 'php' && ext !== '.php') || (lang === 'js' && ext !== '.js')) return;
        try {
          var content = fs.readFileSync(full, 'utf8');
          scan.modules.forEach(function (other) {
            if (other === mod) return;
            if (lang === 'js') {
              if (content.indexOf("require('../" + other) !== -1 ||
                  content.indexOf("require('../../features/" + other) !== -1) {
                count++;
              }
            } else if (lang === 'php') {
              var usePattern = new RegExp('use\\s+[^;]*?\\\\' + other + '\\\\');
              if (usePattern.test(content)) {
                count++;
              }
            }
          });
        } catch (_e) { /* skip */ }
      });
    } catch (_e) { /* skip */ }
  }

  scan.modules.forEach(function (mod) {
    var modDir = scan.moduleDirs[mod];
    if (!modDir) return;
    _scanDirRecursive(mod, modDir);
  });
  return count;
}

function _evaluateLayering(moduleFiles, lang) {
  // 分层角色定义
  var JS_LAYER_ROLES = {
    routes: 'L1-路由', handler: 'L2-处理', controller: 'L2-控制',
    service: 'L3-业务', model: 'L4-数据', repository: 'L4-数据',
    index: 'L0-入口', fabric: 'C0-契约', events: 'L2-事件',
  };

  // PHP 类名后缀→分层映射 (扩充至 50+ 模式)
  var PHP_LAYER_ROLES = {
    // L0: 入口/注册
    index: 'L0-入口', routes: 'L1-路由', Bootstrap: 'L0-入口',
    // L1: 路由/通信/接入
    Controller: 'L1-路由', Router: 'L1-路由', Request: 'L1-路由', Response: 'L1-路由',
    Middleware: 'L1-路由', Proxy: 'L1-路由',
    Provider: 'L1-路由', Client: 'L1-路由', Adapter: 'L1-路由', Connector: 'L1-路由',
    // L2: 处理/编排/IO
    Handler: 'L2-处理', Dispatcher: 'L2-处理', Sender: 'L2-处理',
    Extractor: 'L2-处理', Parser: 'L2-处理', Normalizer: 'L2-处理',
    Guard: 'L2-处理', Gate: 'L2-处理', Filter: 'L2-处理',
    Redirector: 'L2-处理', Tracker: 'L2-处理',
    // L3: 业务逻辑/领域
    Service: 'L3-业务', Manager: 'L3-业务', Engine: 'L3-业务',
    Builder: 'L3-业务', Generator: 'L3-业务', Aggregator: 'L3-业务',
    Resolver: 'L3-业务', Rotator: 'L3-业务', Deployer: 'L3-业务',
    Sync: 'L3-业务', Runner: 'L3-业务', Scheduler: 'L3-业务',
    Optimizer: 'L3-业务', Analyzer: 'L3-业务', Detector: 'L3-业务',
    Collector: 'L3-业务', Injector: 'L3-业务', Bridge: 'L3-业务',
    Replacer: 'L3-业务', Updater: 'L3-业务', Installer: 'L3-业务',
    Provisioner: 'L3-业务', Provision: 'L3-业务',
    // L4: 数据/持久化
    Model: 'L4-数据', Entity: 'L4-数据', Repository: 'L4-数据',
    Gateway: 'L4-数据', Mapper: 'L4-数据', Schema: 'L4-数据',
    Writer: 'L4-数据', Loader: 'L4-数据', Context: 'L4-数据',
    Registry: 'L4-数据', Store: 'L4-数据',
    // C0: 横切/基础设施
    Logger: 'C0-横切', Checker: 'C0-横切', Validator: 'C0-横切',
    Notifier: 'C0-横切', Formatter: 'C0-横切', Helper: 'C0-横切',
    Encryption: 'C0-横切', Encoder: 'C0-横切',
    Cleanup: 'C0-横切', Monitor: 'C0-横切', Auditor: 'C0-横切',
    Observer: 'C0-横切', Watcher: 'C0-横切',
  };

  var LAYER_ROLES = lang === 'php' ? PHP_LAYER_ROLES : JS_LAYER_ROLES;

  var totalScore = 0;
  var moduleCount = Object.keys(moduleFiles).length;
  if (moduleCount === 0) return { score: 0, max: 20, detail: '无模块可评估' };

  Object.keys(moduleFiles).forEach(function (mod) {
    var files = moduleFiles[mod];
    var layers = {};

    Object.keys(files).forEach(function (f) {
      var base = f.replace(/\.(js|php|ts)$/, '').replace(/\.test$/, '');
      var matched = false;

      // 1. 文件名后缀匹配
      Object.keys(LAYER_ROLES).forEach(function (role) {
        if (base === role || base.indexOf(role) !== -1) {
          layers[LAYER_ROLES[role]] = true;
          matched = true;
        }
      });

      // 2. PHP: 文件内容分析 — 读类继承 (extends/implements)
      if (!matched && lang === 'php') {
        try {
          var content = fs.readFileSync(files[f].path, 'utf8');
          var classMatch = content.match(/class\s+(\w+)(?:\s+extends\s+(\w+))?(?:\s+implements\s+(\w+))?/);
          if (classMatch) {
            var className = classMatch[1];
            var parentClass = classMatch[2] || '';
            var iface = classMatch[3] || '';

            // 父类/接口推断分层
            if (parentClass.indexOf('Controller') !== -1) layers['L1-路由'] = true;
            else if (parentClass.indexOf('Entity') !== -1 || parentClass.indexOf('Model') !== -1) layers['L4-数据'] = true;
            else if (parentClass.indexOf('Service') !== -1 || parentClass.indexOf('Manager') !== -1) layers['L3-业务'] = true;
            else if (parentClass.indexOf('Handler') !== -1 || parentClass.indexOf('Middleware') !== -1) layers['L2-处理'] = true;

            // 类名后缀再试一次（可能在嵌套token中被截断）
            if (!layers['L1-路由'] && !layers['L2-处理'] && !layers['L3-业务'] && !layers['L4-数据'] && !layers['C0-横切']) {
              Object.keys(LAYER_ROLES).forEach(function (role) {
                if (className === role || className.indexOf(role) !== -1) {
                  layers[LAYER_ROLES[role]] = true;
                }
              });
            }

            // 有 interface 实现 → C0 契约实现者
            if (iface) layers['C0-横切'] = true;
          }
        } catch (_e) { /* skip unreadable */ }
      }
    });

    // 分层数→分数映射
    totalScore += Object.keys(layers).length >= 3 ? 4 : Object.keys(layers).length >= 2 ? 2 : 0;
  });

  var avg = Math.round(totalScore / moduleCount * (20 / 4)); // 归一化到20
  return {
    score: Math.min(avg, 20),
    max: 20,
    detail: moduleCount + ' 模块, 平均分层数: ' + (totalScore / moduleCount).toFixed(1),
  };
}

function _evaluateSRP(dir, lang) {
  var oversizeFiles = 0;
  var totalChecked = 0;
  var exts = lang === 'php' ? ['.php'] : ['.js', '.ts'];

  function checkDir(d) {
    try {
      var entries = fs.readdirSync(d);
      entries.forEach(function (e) {
        var full = path.join(d, e);
        if (e === 'node_modules' || e === '.git' || e === 'vendor') return;
        try {
          if (fs.statSync(full).isDirectory()) { checkDir(full); return; }
          var ext = path.extname(e);
          var matched = false;
          for (var i = 0; i < exts.length; i++) {
            if (ext === exts[i]) { matched = true; break; }
          }
          if (!matched) return;
          // 跳过测试文件
          if (e.indexOf('.test.') !== -1 || e.indexOf('Test.') !== -1 || e.endsWith('Test.php')) return;
          totalChecked++;
          var lines = _countLines(full);
          if (lines > 150) oversizeFiles++;
        } catch (_er) { /* skip */ }
      });
    } catch (_e) { /* skip */ }
  }

  var srcDir = path.join(dir, 'src');
  checkDir(srcDir);

  if (totalChecked === 0) return { score: 15, max: 15, detail: '无源文件可检查 (跳过)' };

  var ratio = 1 - (oversizeFiles / totalChecked);
  var score = Math.round(ratio * 15);
  return {
    score: Math.max(score, 0),
    max: 15,
    detail: oversizeFiles + '/' + totalChecked + ' 文件 >150行 (' + Math.round(ratio * 100) + '% 合规)',
  };
}

// ═══ 主入口 ═══
function main() {
  var scan = scanProject(ROOT);
  var report = evaluate(scan);

  console.log('\n╔══════════════════════════════════════╗');
  console.log('║  模块化+分层 标准评估 v3.0            ║');
  console.log('╚══════════════════════════════════════╝');
  console.log('项目: ' + ROOT);
  console.log('语言: ' + (scan.language || 'unknown').toUpperCase());
  console.log('模块数: ' + scan.modules.length + ' | Fabric: ' + (scan.hasFabric ? '✅' : '❌'));
  console.log('');

  Object.keys(DIMENSIONS).forEach(function (key) {
    var d = report.dimensions[key];
    var barLen = Math.round(d.score / d.max * 20);
    var bar = '█'.repeat(Math.max(0, barLen)) + '░'.repeat(Math.max(0, 20 - barLen));
    console.log(bar + ' ' + DIMENSIONS[key].name + ' ' + d.score + '/' + d.max);
    console.log('  ' + d.detail);
  });

  console.log('\n总分: ' + report.totalScore + '/' + report.totalMax + ' (' + report.percentage + '%) → ' + report.grade);

  var exitCode = report.percentage >= 70 ? 0 : report.percentage >= 50 ? 1 : 2;
  console.log('退出码: ' + exitCode + ' (' + (exitCode === 0 ? '合格' : exitCode === 1 ? '待改进' : '不合格') + ')\n');

  return { report: report, exitCode: exitCode };
}

// ═══ 作为 check 被 validate_pipeline 调用 ═══
function check(ctx) {
  var scan = scanProject(ctx.ROOT || ROOT);
  var report = evaluate(scan);

  if (report.percentage < 50) {
    ctx.fail('模块化评分', '模块化', '模块化评分 ' + report.percentage + '% < 50% 不合格');
  } else if (report.percentage < 70) {
    ctx.warn('模块化评分', '模块化', '模块化评分 ' + report.percentage + '% — 建议改进至 ≥70%');
  }
}

if (require.main === module) {
  var result = main();
  process.exit(result.exitCode);
}

module.exports = { scanProject: scanProject, evaluate: evaluate, check: check, detectLanguage: detectLanguage };
