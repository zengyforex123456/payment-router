#!/usr/bin/env node
// ═══ Knowledge Capture — Memory(.md) + KAG(JSONL) 双写 ═══
// 单一职责: 知识条目 → Memory 文件 + KAG JSONL → 验证
// 用法: node knowledge-capture.js <json-entry-file>
//       或 echo '{"title":"...","content":{}}' | node knowledge-capture.js --stdin
//
// 触发: 调试完成 / 新错误模式 / 架构决策
// 约定: 必须双写, 不可只写一处

const fs = require('fs');
const path = require('path');
const { append } = require('./kag-db');

const MEMORY_DIR = path.join(__dirname, '..', '..', 'memory');
const MEMORY_INDEX = path.join(MEMORY_DIR, 'MEMORY.md');

// ─── Memory 写入 ───
function writeMemory(entry) {
    const slug = entry.name || entry.title.toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .substring(0, 60);

    const md = `---
name: ${slug}
description: ${entry.description || entry.title}
metadata:
  type: ${entry.metadata_type || 'feedback'}
---
# ${entry.title}
${entry.content.detail || entry.content}

${entry.detection ? `**检测模式**: ${entry.detection}\n` : ''}
${entry.root_cause ? `**根因**: ${entry.root_cause}\n` : ''}
${entry.fix_steps ? `**修复**: ${entry.fix_steps}\n` : ''}
${entry.verification ? `**验证**: ${entry.verification}\n` : ''}

${entry.related ? entry.related.map(r => `**关联**: [[${r}]]`).join('\n') : ''}
`;

    const filePath = path.join(MEMORY_DIR, `${slug}.md`);
    fs.writeFileSync(filePath, md.trim() + '\n', 'utf-8');
    console.log(`   Memory: ${slug}.md`);

    // 更新索引
    updateIndex(slug, entry.description || entry.title);

    return slug;
}

function updateIndex(slug, description) {
    if (!fs.existsSync(MEMORY_INDEX)) {
        fs.writeFileSync(MEMORY_INDEX, '', 'utf-8');
    }
    const line = `- [${description}](${slug}.md) — ${description}`;
    let content = fs.readFileSync(MEMORY_INDEX, 'utf-8');
    if (!content.includes(`${slug}.md`)) {
        content = content.trim() + '\n' + line + '\n';
        fs.writeFileSync(MEMORY_INDEX, content, 'utf-8');
    }
}

// ─── KAG 写入 ───
function writeKAG(entry) {
    return append({
        type: entry.metadata_type || 'feedback',
        title: entry.title,
        description: entry.description || '',
        content: typeof entry.content === 'string' ? entry.content : (entry.content || {}),
        tags: entry.tags || [],
        maturity: entry.maturity || '已验证',
        detection: entry.detection || '',
        root_cause: entry.root_cause || '',
        fix_steps: entry.fix_steps || '',
        verification: entry.verification || ''
    });
}

// ─── 验证 ───
function verify(slug) {
    const memoryExists = fs.existsSync(path.join(MEMORY_DIR, `${slug}.md`));
    const { readAll } = require('./kag-db');
    const entities = readAll();
    const kagEntry = entities.find(e => e.title && e.title.toLowerCase().includes(slug.replace(/-/g, ' ').substring(0, 10)));
    const kagExists = !!kagEntry;

    return {
        memory: memoryExists,
        kag: kagExists,
        synced: memoryExists && kagExists
    };
}

// ─── 批量写入 ───
function captureBatch(entries) {
    const results = [];
    for (const entry of entries) {
        console.log(`\n📝 ${entry.title}`);
        const slug = writeMemory(entry);
        const kagResult = writeKAG(entry);
        const v = verify(slug);
        results.push({ slug, ...v });
        console.log(`   KAG: id=${kagResult.lastInsertRowid}`);
        console.log(`   ✅ 双写 ${v.synced ? '成功' : '⚠️ 不一致'}`);
    }
    return results;
}

// ─── CLI ───
if (require.main === module) {
    let entries = [];

    if (process.argv.includes('--stdin')) {
        // 从 stdin 读取 (支持管道)
        const chunks = [];
        process.stdin.on('data', c => chunks.push(c));
        process.stdin.on('end', () => {
            try {
                const data = JSON.parse(Buffer.concat(chunks).toString());
                entries = Array.isArray(data) ? data : [data];
                captureBatch(entries);
            } catch (e) {
                console.error('❌ Invalid JSON from stdin:', e.message);
                process.exit(1);
            }
        });
    } else if (process.argv[2] === '--verify') {
        // 验证双写一致性
        const { readAll } = require('./kag-db');
        const kagCount = readAll().length;
        const memFiles = fs.readdirSync(MEMORY_DIR).filter(f => f.endsWith('.md') && f !== 'MEMORY.md');
        console.log(`Memory: ${memFiles.length} files`);
        console.log(`KAG: ${kagCount} entities`);
        console.log(kagCount >= memFiles.length ? '✅ KAG >= Memory' : '⚠️  KAG < Memory');
    } else {
        // 从文件读取
        const file = process.argv[2];
        if (file && fs.existsSync(file)) {
            entries = JSON.parse(fs.readFileSync(file, 'utf-8'));
            entries = Array.isArray(entries) ? entries : [entries];
        } else {
            console.log('Usage: node knowledge-capture.js <entries.json>');
            console.log('       echo \'[...]\' | node knowledge-capture.js --stdin');
            console.log('       node knowledge-capture.js --verify');
            process.exit(1);
        }
        captureBatch(entries);
    }
}

module.exports = { writeMemory, writeKAG, verify, captureBatch };
