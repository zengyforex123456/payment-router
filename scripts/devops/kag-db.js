#!/usr/bin/env node
// ═══ KAG Database — JSONL 知识图谱存储 ═══
// 单一职责: 初始化 + 管理 kag_entities (JSONL格式, 零依赖)
// 用途: append-only 知识条目, 可 grep/sort/jq 查询
// 用法: node kag-db.js [init|list|search|stats]
const fs = require('fs');
const path = require('path');

const DATA_DIR = path.join(__dirname, '..', '..', 'data');
const KAG_FILE = path.join(DATA_DIR, 'kag-entities.jsonl');

function ensureDir() {
    if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
}

// ─── 读写操作 ───
function readAll() {
    ensureDir();
    if (!fs.existsSync(KAG_FILE)) return [];
    const raw = fs.readFileSync(KAG_FILE, 'utf-8').trim();
    if (!raw) return [];
    return raw.split('\n')
        .filter(Boolean)
        .map(line => JSON.parse(line));
}

function append(entity) {
    ensureDir();
    const entities = readAll();
    const id = entities.length > 0 ? Math.max(...entities.map(e => e.id)) + 1 : 1;
    const row = {
        id,
        type: entity.type || 'feedback',
        title: entity.title || '',
        description: entity.description || '',
        content: entity.content || {},
        tags: entity.tags || [],
        maturity: entity.maturity || '已验证',
        detection: entity.detection || '',
        root_cause: entity.root_cause || '',
        fix_steps: entity.fix_steps || '',
        verification: entity.verification || '',
        created_at: new Date().toISOString(),
        referenced_count: 0
    };
    fs.appendFileSync(KAG_FILE, JSON.stringify(row) + '\n', 'utf-8');
    return row;
}

function search(query) {
    return readAll().filter(e =>
        (e.title && e.title.includes(query)) ||
        (e.description && e.description.includes(query)) ||
        (e.detection && e.detection.includes(query)) ||
        (JSON.stringify(e.content || {}).includes(query))
    ).sort((a, b) => b.id - a.id);
}

function listRecent(limit = 10) {
    return readAll().slice(-limit).map(e => ({
        id: e.id, type: e.type, title: e.title,
        description: e.description, maturity: e.maturity, created_at: e.created_at
    }));
}

function getStats() {
    const entities = readAll();
    const byType = {};
    entities.forEach(e => { byType[e.type] = (byType[e.type] || 0) + 1; });
    return { total: entities.length, by_type: byType, file: KAG_FILE };
}

// CLI
if (require.main === module) {
    const cmd = process.argv[2] || 'stats';

    switch (cmd) {
        case 'init':
            ensureDir();
            if (!fs.existsSync(KAG_FILE)) fs.writeFileSync(KAG_FILE, '', 'utf-8');
            console.log('✅ KAG (JSONL) initialized:', KAG_FILE);
            console.log(JSON.stringify(getStats(), null, 2));
            break;
        case 'list':
            listRecent(parseInt(process.argv[3]) || 10).forEach(e =>
                console.log(`[${e.id}] ${e.type}: ${e.title} (${e.maturity}) — ${e.created_at}`)
            );
            break;
        case 'search':
            search(process.argv[3] || '').forEach(e =>
                console.log(`[${e.id}] ${e.title}\n  ${e.description}\n  detection: ${e.detection}`)
            );
            break;
        case 'stats':
            console.log(JSON.stringify(getStats(), null, 2));
            break;
        default:
            console.log('Usage: node kag-db.js [init|list|search|stats]');
    }
}

module.exports = { readAll, append, search, listRecent, getStats, ensureDir };
