#!/usr/bin/env python
"""Convert kag-entries.json to docs/troubleshooting/ Markdown files."""
import json, os

BASE = os.path.dirname(os.path.abspath(__file__))
entries = json.load(open(os.path.join(BASE, 'kag-entries.json'), encoding='utf-8'))
docs_dir = os.path.join(BASE, '..', '..', 'docs', 'troubleshooting')
os.makedirs(docs_dir, exist_ok=True)

index_lines = ['# Troubleshooting Guide\n', '\n## Index\n']

for e in entries:
    name = e['name']
    md_path = os.path.join(docs_dir, f'{name}.md')
    with open(md_path, 'w', encoding='utf-8') as f:
        f.write(f'# {e["title"]}\n\n')
        f.write(f'> Status: {e["maturity"]} | Tags: {", ".join(e["tags"])}\n\n')
        f.write(f'## Detection\n\n```\n{e["detection"]}\n```\n\n')
        f.write(f'## Root Cause\n\n{e["root_cause"]}\n\n')
        f.write(f'## Fix\n\n{e["fix_steps"]}\n\n')
        f.write(f'## Verify\n\n```bash\n{e["verification"]}\n```\n')
        if 'content' in e and 'summary' in e.get('content', {}):
            f.write(f'\n### Notes\n\n{e["content"]["summary"]}\n')
    index_lines.append(f'- [{e["title"]}]({name}.md) — {e["description"]}\n')

with open(os.path.join(docs_dir, 'INDEX.md'), 'w', encoding='utf-8') as f:
    f.writelines(index_lines)

print(f'Generated {len(entries)} docs in docs/troubleshooting/')
