#!/usr/bin/env node
/**
 * ci/mutation-gate.cjs — Converge E2E 变异测试门禁
 *
 * 参考: zhice-os ci/stryker-pr.js (增量变异·分数门禁·存活体分析)
 * 差异: zhice-os 变异源码(core/*.js), Converge 变异测试断言(E2E expect)
 *
 * 用法: node ci/mutation-gate.cjs [--quick] [--target-score=60]
 *
 * 三模式:
 *   --quick     只测 3 个核心 E2E 文件 (<2min, PR 用)
 *   --full      全量 22 个 E2E 文件 (10-15min, 发布前)
 *   (默认)      测最近变更的 E2E 文件 (git diff)
 */
var { execSync } = require('child_process');
var path = require('path');
var fs = require('fs');

var ROOT = path.join(__dirname, '..');
var QUICK = process.argv.includes('--quick');
var FULL = process.argv.includes('--full');
var TARGET = parseInt((process.argv.find(function(a) { return a.startsWith('--target-score='); }) || '=50').split('=')[1]) || 50;

var E2E_DIR = path.join(ROOT, 'tests', 'E2E');
var REPORTS_DIR = path.join(ROOT, 'reports');

// ═══ 变异算子 (与 zhice-os Stryker 对齐: 算术/逻辑/边界/删除) ═══
var MUTATORS = [
  { name: 'InvertBool', apply: function(l) { return l.replace(/\.toBe\((true|false)\)/g, function(_,v) { return '.toBe(' + (v==='true'?'false':'true') + ')'; }); } },
  { name: 'WeakenBound', apply: function(l) { return l.replace(/\.toHaveCount\((\d+)\)/g, function(_,n) { return '.toHaveCount(' + Math.max(0,n-2) + ')'; }); } },
  { name: 'InvertCheck', apply: function(l) { return l.replace(/\.toBeLessThan\(/g, '.toBeGreaterThan('); } },
  { name: 'SwapEqual', apply: function(l) { return l.replace(/\.toEqual\(\[\]\)/g, '.toEqual(["MUTATED"])'); } },
  { name: 'RemoveAssert', apply: function(l) { if (l.match(/^\s*(await\s+)?expect\(/)) return '// MUTATED'; return l; } },
  { name: 'FlipContain', apply: function(l) { return l.replace(/\.not\.toContain\(/g, '.toContain('); } },
];

// ═══ 选文件 ═══
function selectFiles() {
  var all = fs.readdirSync(E2E_DIR).filter(function(f) { return f.endsWith('.spec.js'); });

  if (QUICK) {
    // S0 核心: Activity Bar + 公开页 + 租户隔离
    var s0 = ['concurrency.spec.js', 'data-consistency.spec.js', 'payment-closed-loop.spec.js', 'full-chain.spec.js'];
    return s0.filter(function(f) { return all.indexOf(f) !== -1; });
  }

  if (FULL) return all;

  // 增量: git diff 变更的 E2E 文件
  try {
    var changed = execSync('git diff --name-only HEAD~3', { cwd: ROOT, timeout: 5000, encoding: 'utf8' }).trim().split('\n').filter(function(f) { return f.includes('tests/E2E/') && f.endsWith('.spec.js'); });
    if (changed.length > 0) return changed.map(function(f) { return path.basename(f); }).slice(0, 8);
  } catch (_) {}

  return all.slice(0, 6); // fallback: 前 6 个
}

// ═══ 跑测试 ═══
function runPlaywright(testFiles) {
  var args = testFiles.map(function(f) { return path.join(E2E_DIR, f); });
  try {
    var opts = { cwd: ROOT, timeout: 180000, encoding: 'utf8', stdio: 'pipe' };
    var env = Object.assign({}, process.env, {
      E2E_URL: process.env.E2E_URL || 'http://137.184.225.93',
      E2E_PASS: process.env.E2E_PASS || 'admin123',
      CI: 'true'
    });
    opts.env = env;
    var result = execSync('npx playwright test ' + args.join(' ') + ' --reporter=line 2>&1', opts);
    var out = result.toString();
    return { output: out, failed: (out.match(/failed/g) || []).length, passed: (out.match(/passed/g) || []).length };
    return { output: result, failed: (result.match(/failed/g) || []).length, passed: (result.match(/passed/g) || []).length };
  } catch (e) {
    var out = (e.stdout || '') + (e.stderr || '') + (e.message || '');
    return { output: out, failed: (out.match(/failed/g) || []).length, passed: (out.match(/passed/g) || []).length };
  }
}

// ═══ 主流程 ═══
var files = selectFiles();
console.log('🧬 Converge 变异测试门禁 (参考 zhice-os ci/stryker-pr.js)');
console.log('模式: ' + (QUICK ? '快速' : FULL ? '全量' : '增量'));
console.log('目标: ' + TARGET + '%  文件: ' + files.length + '\n');

// 基线
console.log('📊 基线...');
var baseline = runPlaywright(files);
console.log('   ' + baseline.passed + ' passed, ' + baseline.failed + ' failed');
if (baseline.failed > 0) { console.log('⚠️  基线有失败, 先修好再跑变异'); process.exit(1); }

// 变异
var total = 0, killed = 0, survived = [];
for (var fi = 0; fi < files.length; fi++) {
  var file = files[fi];
  var filePath = path.join(E2E_DIR, file);
  var lines = fs.readFileSync(filePath, 'utf8').split('\n');

  for (var li = 0; li < lines.length; li++) {
    var line = lines[li];
    if (!line.match(/^\s*(await\s+)?expect\(/)) continue;

    for (var mi = 0; mi < MUTATORS.length; mi++) {
      var m = MUTATORS[mi];
      var mutated = m.apply(line);
      if (mutated === line) continue; // 无变化跳过
      total++;

      var tmpLines = lines.slice();
      tmpLines[li] = mutated;
      var tmpFile = filePath.replace('.spec.js', '.mutant-' + total + '.spec.js');
      fs.writeFileSync(tmpFile, tmpLines.join('\n'));

      var result = runPlaywright([path.basename(tmpFile)]);
      try { fs.unlinkSync(tmpFile); } catch (_) {}

      if (result.failed > 0) {
        killed++;
        process.stdout.write('✅');
      } else {
        survived.push({ file: file, line: li+1, mutator: m.name, original: line.trim(), mutated: mutated.trim() });
        process.stdout.write('❌');
      }
    }
  }
}

// ═══ 报告 (zhice-os 格式) ═══
var score = total > 0 ? Math.round(killed / total * 100) : 0;
console.log('\n\n═══ 变异测试报告 ═══');
console.log('变异体: ' + total + '  杀死: ' + killed + '  存活: ' + survived.length + '  分数: ' + score + '%');
console.log('门禁: ' + (score >= TARGET ? '✅ 通过 (≥' + TARGET + '%)' : '❌ 阻塞 (需要 ≥' + TARGET + '%)'));

if (survived.length > 0) {
  console.log('\n🔴 Top 存活体:');
  var byFile = {};
  survived.forEach(function(s) { var k = s.file + ':' + s.mutator; byFile[k] = (byFile[k] || 0) + 1; });
  var top = Object.entries(byFile).sort(function(a,b) { return b[1] - a[1]; }).slice(0, 8);
  top.forEach(function(e) { console.log('  ' + e[1] + 'x ' + e[0]); });

  console.log('\n📝 明细 (前 5):');
  survived.slice(0, 5).forEach(function(s) {
    console.log('  ' + s.file + ':' + s.line + ' [' + s.mutator + ']');
    console.log('    ' + s.original.slice(0, 70));
  });
}

// 保存
if (!fs.existsSync(REPORTS_DIR)) fs.mkdirSync(REPORTS_DIR, { recursive: true });
fs.writeFileSync(path.join(REPORTS_DIR, 'mutation-score.json'), JSON.stringify([{ score: score, total: total, killed: killed, survived: survived.length, files: files.length, timestamp: new Date().toISOString() }], null, 2));
console.log('\n报告: reports/mutation-score.json');

process.exit(score >= TARGET ? 0 : 1);
