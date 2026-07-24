#!/usr/bin/env node
// @ts-nocheck
// checks/modularization-ratchet.js — 模块化评分棘轮
// 单一功能: 本次评分 vs 基线 → 下降则阻断
// 用法: node checks/modularization-ratchet.js [项目目录]
// 退出码: 0=通过(≥基线), 1=下降但≥70, 2=下降且<70(阻断)

var path = require('path');
var fs = require('fs');
var { scanProject, evaluate } = require('./modularization-score');

var ROOT = process.argv[2] || '.';
ROOT = path.resolve(ROOT);

var BASELINE_FILE = path.join(ROOT, '.claude', 'modularization-baseline.json');

// ═══ 读基线 ═══
function readBaseline() {
  try {
    return JSON.parse(fs.readFileSync(BASELINE_FILE, 'utf8'));
  } catch (_e) {
    return null;
  }
}

// ═══ 写基线 ═══
function writeBaseline(report) {
  var dir = path.dirname(BASELINE_FILE);
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(BASELINE_FILE, JSON.stringify({
    score: report.percentage,
    grade: report.grade,
    updatedAt: new Date().toISOString(),
    dimensions: Object.keys(report.dimensions).reduce(function (acc, k) {
      acc[k] = report.dimensions[k].score;
      return acc;
    }, {}),
  }, null, 2), 'utf8');
}

// ═══ 主流程 ═══
function main() {
  var report = evaluate(scanProject(ROOT));
  var baseline = readBaseline();

  // 打印当前分数
  console.log('\n📊 模块化评分: ' + report.percentage + '% (' + report.grade + ')');

  if (baseline) {
    console.log('📏 基线分数: ' + baseline.score + '% (保存于 ' + baseline.updatedAt + ')');
    var diff = report.percentage - baseline.score;

    if (diff < 0) {
      console.log('📉 分数下降 ' + diff + '% — 不可接受');
      // 打印下降的维度
      Object.keys(report.dimensions).forEach(function (k) {
        var prev = baseline.dimensions ? baseline.dimensions[k] : null;
        if (prev !== null && report.dimensions[k].score < prev) {
          console.log('  ↓ ' + k + ': ' + prev + ' → ' + report.dimensions[k].score);
        }
      });

      if (report.percentage < 70) {
        console.log('🔴 阻断: 分数下降至 ' + report.percentage + '% < 70%');
        process.exit(2);
      } else {
        console.log('🟡 警告: 分数下降但仍 ≥70%，请修复后提交');
        process.exit(1);
      }
    } else if (diff > 0) {
      console.log('📈 分数提升 +' + diff + '% — 更新基线');
      writeBaseline(report);
    } else {
      console.log('➡️ 分数持平');
    }
  } else {
    // 无基线 → 创建
    console.log('📝 首次评估，创建基线: ' + report.percentage + '%');
    writeBaseline(report);

    if (report.percentage < 50) {
      console.log('🔴 初始评分 ' + report.percentage + '% < 50%，建议立即改进');
      process.exit(2);
    }
  }

  console.log('');
  process.exit(0);
}

main();
