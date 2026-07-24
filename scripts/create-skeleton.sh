#!/bin/bash
# create-skeleton.sh — Generate a clean Converge skeleton project
# Usage: bash scripts/create-skeleton.sh /path/to/new-project
set -e
SRC="E:/project/converge"
DST="${1:-E:/project/converge-skeleton}"

echo "🏗 Creating skeleton project at: $DST"
mkdir -p "$DST"

# ═══ 1. Copy framework directories ═══
echo "📁 Copying kernel/..."
cp -r "$SRC/kernel" "$DST/"

echo "📁 Copying config/..."
cp -r "$SRC/config" "$DST/"

echo "📁 Copying .claude/..."
cp -r "$SRC/.claude" "$DST/"
rm -rf "$DST/.claude/projects"  # Remove project-specific memory

echo "📁 Copying scripts/..."
cp -r "$SRC/scripts" "$DST/"

echo "📁 Copying database/migrations (framework only)..."
mkdir -p "$DST/database/migrations"
cp "$SRC/database/migrations/001_create_users_table.sql" "$DST/database/migrations/"
cp "$SRC/database/migrations/032_create_roles_and_permissions.sql" "$DST/database/migrations/"
cp "$SRC/database/migrations/083_create_sessions_table.sql" "$DST/database/migrations/"

echo "📁 Copying templates (framework only)..."
mkdir -p "$DST/templates"
cp "$SRC/templates/_layout.latte" "$DST/templates/"
cp "$SRC/templates/index.latte" "$DST/templates/"
cp -r "$SRC/templates/_layouts" "$DST/templates/"
cp -r "$SRC/templates/_partials" "$DST/templates/"
cp -r "$SRC/templates/atoms" "$DST/templates/"
cp -r "$SRC/templates/molecules" "$DST/templates/"
cp -r "$SRC/templates/patterns" "$DST/templates/"
mkdir -p "$DST/templates/pages"
cp "$SRC/templates/pages/error-404.latte" "$DST/templates/pages/"
cp "$SRC/templates/pages/error-500.latte" "$DST/templates/pages/"
mkdir -p "$DST/templates/_content"
touch "$DST/templates/_content/.gitkeep"

echo "📁 Copying public/ (framework only)..."
mkdir -p "$DST/public"
cp "$SRC/public/index.php" "$DST/public/"
cp "$SRC/public/logout.php" "$DST/public/"
cp "$SRC/public/health.php" "$DST/public/"
# CSS framework files
mkdir -p "$DST/public/build/css"
cp "$SRC/public/build/css/app-shell.css" "$DST/public/build/css/"
cp "$SRC/public/build/css/design-tokens.css" "$DST/public/build/css/"
cp "$SRC/public/build/css/grid.css" "$DST/public/build/css/"
cp "$SRC/public/build/css/precision-common.css" "$DST/public/build/css/"
cp "$SRC/public/build/css/stitch-theme.css" "$DST/public/build/css/"
cp "$SRC/public/build/css/tailwind.min.css" "$DST/public/build/css/"
cp "$SRC/public/build/css/missing-utilities.css" "$DST/public/build/css/"
cp "$SRC/public/build/css/container-queries.css" "$DST/public/build/css/"
cp "$SRC/public/build/css/app-bundle.css" "$DST/public/build/css/"
cp "$SRC/public/build/css/builder.css" "$DST/public/build/css/"
# JS framework files
mkdir -p "$DST/public/build/js/controllers"
cp "$SRC/public/build/js/stimulus-app.js" "$DST/public/build/js/"
cp "$SRC/public/build/js/alpinejs.min.js" "$DST/public/build/js/"
cp "$SRC/public/build/js/htmx.min.js" "$DST/public/build/js/"
cp "$SRC/public/build/js/sortable.min.js" "$DST/public/build/js/"
cp "$SRC/public/build/js/theme-toggle.js" "$DST/public/build/js/"
cp "$SRC/public/build/js/toast.js" "$DST/public/build/js/"
cp "$SRC/public/build/js/api.js" "$DST/public/build/js/"
cp "$SRC/public/build/js/bundle.min.js" "$DST/public/build/js/"
cp "$SRC/public/build/js/component-registry.json" "$DST/public/build/js/"
# Generic controllers only
for c in accordion command_palette dock dropdown modal search sidebar_nav table tabs toggle; do
    cp "$SRC/public/build/js/controllers/${c}_controller.js" "$DST/public/build/js/controllers/" 2>/dev/null || true
done
mkdir -p "$DST/public/assets/images"
touch "$DST/public/assets/images/.gitkeep"
cp "$SRC/public/.htaccess" "$DST/public/"
cp "$SRC/public/404.html" "$DST/public/" 2>/dev/null || true

echo "📁 Copying docker/..."
cp -r "$SRC/docker" "$DST/"
cp "$SRC/Dockerfile" "$DST/" 2>/dev/null || true
cp "$SRC/docker-compose.yml" "$DST/" 2>/dev/null || true
cp "$SRC/docker-compose.dev.yml" "$DST/" 2>/dev/null || true

echo "📁 Copying bin/ (generic + enforcement)..."
mkdir -p "$DST/bin"
cp "$SRC/bin/converge" "$DST/bin/"
cp "$SRC/bin/tool" "$DST/bin/"
cp "$SRC/bin/verify-deps.php" "$DST/bin/"
cp "$SRC/bin/platform-enforce.php" "$DST/bin/"
# Git hooks for quality enforcement
if [ -d "$SRC/.githooks" ]; then cp -r "$SRC/.githooks" "$DST/"; fi

# ═══ 2. Copy root config files ═══
echo "📁 Copying root configuration..."
for f in composer.json composer.lock package.json package-lock.json tailwind.config.js \
         .env.example .gitignore .gitattributes .dockerignore .htaccess \
         .eslintrc.json .prettierrc Makefile; do
    [ -f "$SRC/$f" ] && cp "$SRC/$f" "$DST/" || true
done

# ═══ 3. Create starter files ═══
echo "📝 Creating starter templates..."
mkdir -p "$DST/templates/pages"
cat > "$DST/templates/pages/dashboard.latte" << 'LATTE'
{* Starter Dashboard — Customize this for your project *}
{layout '../_layout.latte'}
{var $zh = $zh ?? false}
{block content}
<div class="st-dash">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--st-2xl)">
    <div>
      <h1 style="font-size:var(--st-text-display);font-weight:700;color:var(--st-text-primary);margin:0">Dashboard</h1>
      <p style="font-size:var(--st-text-body-sm);color:var(--st-text-tertiary);margin:var(--st-xs) 0 0">Welcome to your new SaaS project.</p>
    </div>
  </div>
  <div class="st-grid st-grid-4">
    <div class="st-kpi"><div class="st-kpi-label">📊 Sample KPI</div><div class="st-kpi-value">0</div><div class="st-kpi-trend up">Start building!</div></div>
    <div class="st-kpi"><div class="st-kpi-label">🚀 Get Started</div><div class="st-kpi-value">1</div><div class="st-kpi-trend">Edit this template</div></div>
    <div class="st-kpi"><div class="st-kpi-label">📖 Docs</div><div class="st-kpi-value">→</div><div class="st-kpi-trend">See .claude/reference/</div></div>
    <div class="st-kpi"><div class="st-kpi-label">🛠️ Build</div><div class="st-kpi-value">php</div><div class="st-kpi-trend">Start coding!</div></div>
  </div>
</div>
{/block}
LATTE

cat > "$DST/public/admin-panel.php" << 'PHP'
<?php
/** admin-panel.php — Main dashboard (starter) */
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../kernel/src/Foundation/Database/db.php';

use Converge\Security\Auth;
use Converge\I18n\Locale;
use Converge\UI\Engine\LatteEngine;

$db = db()->raw();
$auth = new Auth($db);
$auth->requireAuth();
Locale::init();
$lang = Locale::lang();

LatteEngine::display('pages/dashboard', [
    'headExtra' => '',
    'lang' => $lang,
    'zh' => $lang === 'zh',
    'title' => 'Dashboard',
]);
PHP

# Add page-registry for dashboard
mkdir -p "$DST/.claude/reference"
cat > "$DST/.claude/reference/page-registry.json" << 'JSON'
{
    "_version": "1.0",
    "menus": {
        "main": {
            "label_en": "Main",
            "label_zh": "主菜单",
            "label_icon": "home",
            "roles": ["admin"],
            "items": [
                {
                    "id": "dashboard",
                    "label_zh": "仪表盘",
                    "label_en": "Dashboard",
                    "icon": "📊",
                    "url": "/admin-panel.php",
                    "shortcut": "G D"
                }
            ]
        }
    }
}
JSON

echo ""
echo "✅ Skeleton created at: $DST"
echo ""
echo "Next steps:"
echo "  1. cd $DST"
echo "  2. composer install"
echo "  3. cp .env.example .env"
echo "  4. php -S localhost:8080 -t public/"
echo "  5. Start building your modules in modules/YourModule/"
