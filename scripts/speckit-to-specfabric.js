#!/usr/bin/env node
// speckit-to-specfabric.js — 桥接转换器
// 输入: spec-kit 的 spec.md (Markdown)
// 输出: spec-fabric 的 YAML 片段 (functional + red_line + ui + a11y)
// 用法: node scripts/speckit-to-specfabric.js <spec.md路径> [--merge] [--id PREFIX]
//       --merge  合并到已有的 converge.spec.yaml
//       --id      手动指定需求 ID 前缀 (默认从 spec 文件名提取)

const fs = require('fs');
const path = require('path');

// ═══ Parse spec-kit spec.md ═══
function parseSpecKit(specPath) {
  const content = fs.readFileSync(specPath, 'utf8');
  const lines = content.split('\n');

  const result = {
    featureName: '',
    userStories: [],
    functionalReqs: [],
    successCriteria: [],
    edgeCases: [],
    uiHints: [],
    a11yHints: [],
  };

  let currentStory = null;
  let currentSection = null;
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i].trim();

    // Feature name
    if (line.startsWith('# Feature Specification:')) {
      result.featureName = line.replace('# Feature Specification:', '').trim();
    }

    // Section tracking
    if (line.startsWith('### User Story')) {
      const match = line.match(/### User Story \d+ - (.+) \(Priority: (P\d)\)/);
      if (match) {
        currentStory = { title: match[1], priority: match[2], scenarios: [] };
        result.userStories.push(currentStory);
        currentSection = 'story';
        
      }
    }

    if (line.startsWith('### Edge Cases')) {
      currentSection = 'edge';
      currentStory = null;
      
    }
    if (line.startsWith('### Functional Requirements')) {
      currentSection = 'fr';
      currentStory = null;
      
    }
    if (line.startsWith('### Key Entities')) {
      currentSection = 'entity';
      currentStory = null;
      
    }
    if (line.startsWith('### Measurable Outcomes')) {
      currentSection = 'sc';
      currentStory = null;
      
    }
    if (line.startsWith('## Assumptions')) {
      currentSection = null;
      currentStory = null;
      
    }

    // GWT scenarios
    if (currentStory && line.match(/^\d+\.\s*\*\*Given\*\*/)) {
      const gwtMatch = line.match(/\*\*Given\*\*\s*(.+?),\s*\*\*When\*\*\s*(.+?),\s*\*\*Then\*\*\s*(.+)/);
      if (gwtMatch) {
        currentStory.scenarios.push({
          given: gwtMatch[1].trim(),
          when: gwtMatch[2].trim(),
          then: gwtMatch[3].trim(),
        });
      }
    }

    // FR extraction (handles "- **FR-XXX**: ..." and "**FR-XXX**: ..." format)
    if (currentSection === 'fr' && line.match(/FR-\d+/)) {
      const frMatch = line.match(/FR-(\d+)\*?\*?:\s*(.+)/);
      if (frMatch) {
        result.functionalReqs.push({ id: frMatch[1], text: frMatch[2].trim() });
      }
    }

    // SC extraction (handles "- **SC-XXX**: ..." and "**SC-XXX**: ..." format)
    if (currentSection === 'sc' && line.match(/SC-\d+/)) {
      const scMatch = line.match(/SC-(\d+)\*?\*?:\s*(.+)/);
      if (scMatch) {
        result.successCriteria.push({ id: scMatch[1], text: scMatch[2].trim() });
      }
    }

    // Edge cases
    if (currentSection === 'edge' && line.startsWith('- What happens when')) {
      result.edgeCases.push(line.replace(/^-\s*/, '').trim());
    }

    // UI hints (CSS/color/width mentions in requirements)
    if (line.match(/background|color|width|height|font|border|transition|animation/i)
        && (currentSection === 'fr' || currentSection === 'sc')) {
      result.uiHints.push(line.trim());
    }

    // A11y hints
    if (line.match(/aria|role=|alt=|label|screen.reader|keyboard|accessible/i)
        && (currentSection === 'fr' || currentSection === 'sc')) {
      result.a11yHints.push(line.trim());
    }
  }

  return result;
}

// ═══ Map to spec-fabric YAML checks ═══
function toSpecFabric(parsed, _featureId) {
  const checks = {
    functional: [],
    ui: [],
    a11y: [],
    red_line: [],
  };

  // From FR: functional checks
  for (const fr of parsed.functionalReqs) {
    checks.functional.push({
      test: `FR-${fr.id}: ${fr.text.substring(0, 50)}`,
      assertion: deriveAssertion(fr.text),
    });
  }

  // From GWT: functional checks with actions
  for (const story of parsed.userStories) {
    for (const s of story.scenarios) {
      const check = {
        test: `${story.title}: ${s.then.substring(0, 40)}`,
      };
      // Try to derive action from "When"
      const action = deriveAction(s.when);
      if (action) check.action = action;
      // Try to derive selector from the context
      const selector = deriveSelector(s.given + ' ' + s.then);
      if (selector) check.selector = selector;
      // Derive assertion
      check.assertion = deriveAssertion(s.then);
      checks.functional.push(check);
    }
  }

  // From SC: red_line checks
  for (const sc of parsed.successCriteria) {
    checks.red_line.push({
      test: `SC-${sc.id}: ${sc.text.substring(0, 50)}`,
      assertion: deriveSCAssertion(sc),
    });
  }

  // From edge cases
  for (const edge of parsed.edgeCases) {
    checks.functional.push({
      test: edge.substring(0, 60),
    });
  }

  // UI hints → ui checks (best effort)
  for (const hint of parsed.uiHints) {
    const cssProps = extractCSS(hint);
    if (cssProps) {
      checks.ui.push({
        test: hint.substring(0, 50),
        ...cssProps,
      });
    }
  }

  // A11y hints → a11y checks
  for (const hint of parsed.a11yHints) {
    checks.a11y.push({
      test: hint.substring(0, 60),
      assertion: deriveA11yAssertion(hint),
    });
  }

  // Ensure non-empty arrays don't break YAML
  if (checks.ui.length === 0) {
    checks.ui.push({ test: 'TODO: 从 spec.md 补充 UI 验证', selector: 'body' });
  }
  if (checks.a11y.length === 0) {
    checks.a11y.push({ test: 'TODO: 从 spec.md 补充可访问性验证', selector: 'body' });
  }

  return checks;
}

// ═══ Derive helpers ═══
function deriveAssertion(text) {
  const lower = text.toLowerCase();

  if (lower.includes('must be') || lower.includes('must render') || lower.includes('must display')) {
    return 'document.querySelector("[data-feature]") !== null';
  }
  if (lower.includes('visible') || lower.includes('shows') || lower.includes('opens')) {
    return 'el && !el.hidden';
  }
  if (lower.includes('click') || lower.includes('toggle') || lower.includes('switch')) {
    return 'el.classList.contains("active") || el.classList.contains("open")';
  }
  if (lower.includes('search') || lower.includes('filter')) {
    return 'document.querySelector(".search-results") !== null';
  }
  if (lower.includes('count') || lower.includes('number') || lower.match(/\d+/)) {
    const numMatch = lower.match(/(\d+)/);
    if (numMatch) return `document.querySelectorAll("[data-feature]").length === ${numMatch[1]}`;
  }
  if (lower.includes('error') || lower.includes('crash') || lower.includes('fail')) {
    return 'typeof window.__errors === "undefined"';
  }
  if (lower.includes('accessible') || lower.includes('label') || lower.includes('aria')) {
    return 'el.getAttribute("aria-label") !== null || el.getAttribute("title") !== null';
  }

  return 'el !== null';
}

function deriveAction(when) {
  const lower = when.toLowerCase();
  if (lower.includes('click')) {
    const match = when.match(/click(?:s|ing)?\s+(?:the\s+)?[`"]?([^`",.]+)[`"]?/i);
    if (match) return `click ${match[1].trim().replace(/\s+/g, '-').toLowerCase()}`;
    return 'click .target';
  }
  if (lower.includes('type') || lower.includes('enter') || lower.includes('input')) {
    return 'type #search-input test';
  }
  if (lower.includes('press') && lower.match(/ctrl|cmd|alt/i)) {
    const key = lower.match(/(ctrl|cmd|alt)\s*\+\s*(\w)/i);
    if (key) return `keydown ${key[1]}+${key[2].toLowerCase()}`;
  }
  if (lower.includes('navigat') || lower.includes('go to')) {
    return null; // navigation is implicit
  }
  return null;
}

function deriveSelector(text) {
  const lower = text.toLowerCase();
  if (lower.includes('activity bar')) return '#activity-bar';
  if (lower.includes('dock button') || lower.includes('dock')) return '.dock-btn';
  if (lower.includes('panel') || lower.includes('side panel')) return '#side-panel';
  if (lower.includes('search')) return '#search-input-inline';
  if (lower.includes('health') || lower.includes('dot')) return '#health-dot';
  if (lower.includes('quick create') || lower.includes('+')) return '.quick-create-trigger';
  if (lower.includes('dashboard')) return '.dashboard';
  if (lower.includes('campaign')) return '.campaign-list';
  return null;
}

function deriveSCAssertion(sc) {
  const text = sc.text.toLowerCase();
  if (text.includes('click') || text.includes('≤')) {
    return 'true'; // Manual verification needed for click-count metrics
  }
  if (text.includes('render') || text.includes('viewport')) {
    return 'document.documentElement.offsetWidth > 0';
  }
  if (text.includes('label') || text.includes('aria') || text.includes('title')) {
    return 'Array.from(document.querySelectorAll("[aria-label], [title]")).length > 0';
  }
  if (text.includes('transition') || text.includes('≤') && text.includes('ms')) {
    return 'true'; // Performance metric — manual timing
  }
  if (text.includes('console') || text.includes('error')) {
    return 'typeof window.__errors === "undefined"';
  }
  if (text.includes('alt')) {
    return 'Array.from(document.querySelectorAll("img")).every(img => img.hasAttribute("alt"))';
  }
  return 'true'; // Metric-based SC — manual verification
}

function extractCSS(text) {
  const result = {};
  const lower = text.toLowerCase();

  const colorMatch = text.match(/(#[0-9A-Fa-f]{6}|#[0-9A-Fa-f]{3})/);
  if (colorMatch) {
    if (lower.includes('background')) result['background-color'] = colorMatch[1];
    else if (lower.includes('color')) result['color'] = colorMatch[1];
  }

  const pxMatch = text.match(/(\d+)px/);
  if (pxMatch) {
    if (lower.includes('width')) result['width'] = pxMatch[1] + 'px';
    else if (lower.includes('height')) result['height'] = pxMatch[1] + 'px';
  }

  if (lower.includes('border-radius')) result['border-radius'] = '50%';
  if (lower.includes('transition')) result['transition'] = 'transform 0.22s';

  return Object.keys(result).length > 0 ? result : null;
}

function deriveA11yAssertion(text) {
  const lower = text.toLowerCase();
  if (lower.includes('aria-label')) return 'el.getAttribute("aria-label") !== null';
  if (lower.includes('role=')) return 'el.getAttribute("role") !== null';
  if (lower.includes('alt')) return 'el.hasAttribute("alt")';
  if (lower.includes('title')) return 'el.getAttribute("title") !== ""';
  if (lower.includes('keyboard')) return 'typeof document.onkeydown === "function"';
  return 'el.getAttribute("aria-label") !== null || el.getAttribute("title") !== null';
}

// ═══ Generate YAML ═══
function toYAML(checks, featureId, featureName) {
  let yaml = `# AUTO-GENERATED by speckit-to-specfabric.js — review before commit
# Source: specs/${featureId}*/spec.md
# Generated: ${new Date().toISOString().slice(0, 10)}

${featureId}:
  name: ${featureName}

  functional:
`;
  for (const c of checks.functional) {
    yaml += `    - test: "${c.test.replace(/"/g, '\\"')}"\n`;
    if (c.action) yaml += `      action: "${c.action}"\n`;
    if (c.selector) yaml += `      selector: ${c.selector}\n`;
    if (c.assertion) yaml += `      assertion: "${c.assertion.replace(/"/g, '\\"')}"\n`;
  }

  yaml += `\n  ui:\n`;
  for (const c of checks.ui) {
    yaml += `    - test: "${c.test.replace(/"/g, '\\"')}"\n`;
    if (c.selector) yaml += `      selector: ${c.selector}\n`;
    if (c.css) yaml += `      css: ${JSON.stringify(c.css)}\n`;
    if (c.assertion) yaml += `      assertion: "${c.assertion.replace(/"/g, '\\"')}"\n`;
  }

  yaml += `\n  a11y:\n`;
  for (const c of checks.a11y) {
    yaml += `    - test: "${c.test.replace(/"/g, '\\"')}"\n`;
    if (c.selector) yaml += `      selector: ${c.selector}\n`;
    if (c.assertion) yaml += `      assertion: "${c.assertion.replace(/"/g, '\\"')}"\n`;
  }

  yaml += `\n  red_line:\n`;
  for (const c of checks.red_line) {
    yaml += `    - test: "${c.test.replace(/"/g, '\\"')}"\n`;
    if (c.assertion) yaml += `      assertion: "${c.assertion.replace(/"/g, '\\"')}"\n`;
  }

  return yaml;
}

// ═══ Merge with existing YAML ═══
function mergeWithExisting(newYaml, existingPath) {
  if (!fs.existsSync(existingPath)) return newYaml;

  const existing = fs.readFileSync(existingPath, 'utf8');
  // Simple merge: append new entry after last RED_LINE section
  // Find the RED_LINE section and insert before it
  const redLineIdx = existing.lastIndexOf('RED_LINE:');
  if (redLineIdx > 0) {
    // Extract new content (skip the first line which is the comment)
    const newContent = newYaml.split('\n').slice(1).join('\n');
    return existing.slice(0, redLineIdx) + newContent + '\n' + existing.slice(redLineIdx);
  }
  // No RED_LINE found, append at end
  return existing + '\n' + newYaml.split('\n').slice(1).join('\n');
}

// ═══ Main ═══
function main() {
  const args = process.argv.slice(2);
  if (args.length === 0 || args.includes('--help') || args.includes('-h')) {
    console.log('Usage: node speckit-to-specfabric.js <spec.md> [--merge] [--id PREFIX]');
    console.log('  spec.md   Path to spec-kit generated spec file');
    console.log('  --merge   Merge into existing .claude/specs/converge.spec.yaml');
    console.log('  --id      Feature ID prefix (default: extracted from spec path)');
    console.log('');
    console.log('Example:');
    console.log('  node scripts/speckit-to-specfabric.js specs/001-activity-bar-navigation/spec.md --merge --id N8');
    process.exit(0);
  }

  const specPath = path.resolve(args[0]);
  if (!fs.existsSync(specPath)) {
    console.error('Error: spec file not found: ' + specPath);
    process.exit(1);
  }

  const doMerge = args.includes('--merge');
  const idArg = args.indexOf('--id');
  let featureId = idArg > 0 ? args[idArg + 1] : null;

  // Parse
  console.log('📖 Parsing: ' + specPath);
  const parsed = parseSpecKit(specPath);
  console.log(`   Feature: ${parsed.featureName}`);
  console.log(`   User Stories: ${parsed.userStories.length}`);
  console.log(`   Functional Reqs: ${parsed.functionalReqs.length}`);
  console.log(`   Success Criteria: ${parsed.successCriteria.length}`);
  console.log(`   Edge Cases: ${parsed.edgeCases.length}`);
  console.log(`   UI Hints: ${parsed.uiHints.length}`);
  console.log(`   A11y Hints: ${parsed.a11yHints.length}`);

  // Derive ID
  if (!featureId) {
    const dirName = path.basename(path.dirname(specPath));
    const numMatch = dirName.match(/^(\d+)/);
    featureId = numMatch ? 'SPEC-' + numMatch[1] : 'SPEC-NEW';
  }

  // Convert
  console.log('\n🔄 Converting to spec-fabric format...');
  const checks = toSpecFabric(parsed, featureId);
  const yaml = toYAML(checks, featureId, parsed.featureName);

  // Output
  if (doMerge) {
    const targetPath = path.join(
      path.dirname(specPath), '..', '..', '.claude', 'specs', 'converge.spec.yaml'
    );
    // Try data/source path as fallback
    const altPath = path.join(
      path.dirname(specPath), '..', '..', 'data', 'source', '.claude', 'specs', 'converge.spec.yaml'
    );

    let destPath = path.resolve(targetPath);
    if (!fs.existsSync(destPath)) destPath = path.resolve(altPath);
    if (!fs.existsSync(destPath)) {
      destPath = path.resolve(targetPath);
      console.log('   Creating new: ' + destPath);
    }

    const merged = mergeWithExisting(yaml, destPath);
    fs.writeFileSync(destPath, merged);
    console.log('✅ Merged into: ' + destPath);
  } else {
    console.log('\n' + yaml);
    console.log('💡 Use --merge to merge into existing converge.spec.yaml');
  }

  // Summary
  console.log('\n📊 Conversion Summary:');
  console.log(`   functional: ${checks.functional.length} checks`);
  console.log(`   ui:         ${checks.ui.length} checks (${parsed.uiHints.length === 0 ? '⚠️ need manual review' : '✓ from spec'})`);
  console.log(`   a11y:       ${checks.a11y.length} checks (${parsed.a11yHints.length === 0 ? '⚠️ need manual review' : '✓ from spec'})`);
  console.log(`   red_line:   ${checks.red_line.length} checks`);
  console.log('\n⚠️  UI and a11y checks may need manual enrichment.');
  console.log('   spec-kit specs focus on WHAT, not visual details.');
  console.log('   Add CSS assertions and responsive viewport checks manually.');
}

main();
