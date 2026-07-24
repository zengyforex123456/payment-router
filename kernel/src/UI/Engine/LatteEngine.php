<?php

declare(strict_types=1);

namespace Converge\UI\Engine;

use Latte\Engine;
use Converge\UI\ViewContext;

/**
 * LatteEngine �?T2 模板引擎接入�? *
 * 触发场景 (非时�?:
 *   1. 新建复杂列表/报表页面 �?�?Latte �? *   2. 现有页面�?4 次大�?�?顺手�?Latte
 *   3. 函数组件 >10 个且参数混乱 �?批量迁移
 *
 * 用法:
 *   echo LatteEngine::render('pages/dashboard', ['stats' => $stats, 'campaigns' => $campaigns]);
 *
 * 模板路径: templates/{name}.latte
 * 缓存路径: storage/cache/latte/
 */
class LatteEngine
{
    private static ?Engine $engine = null;

    /**
     * 渲染 Latte 模板，返�?HTML 字符串�?     *
     * 自动注入 $context (ViewContext) �?$user 到每个模板�?     * 调用方可通过 $params 覆盖默认值�?     *
     * @param string $template 模板�?(相对 templates/ 目录，不�?.latte 后缀)
     * @param array  $params   模板参数
     * @return string
     */
    public static function render(string $template, array $params = []): string
    {
        // Handle ?set_tz= → persist user timezone to session
        if (!empty($_GET['set_tz'])) {
            $_SESSION['user_tz'] = $_GET['set_tz'];
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        // Inject timezone for navbar (Latte 3 blocks \$GLOBALS access)
        if (!isset($params['userTz'])) {
            $params['userTz'] = $_SESSION['user_tz'] ?? 'UTC';
        }

        $engine = self::getEngine();

        // Auto-inject ViewContext
        if (!isset($params['context'])) {
            $params['context'] = ViewContext::fromGlobals();
        }
        if (!isset($params['user'])) {
            $params['user'] = $params['context']->user;
        }

        // Auto-inject layout defaults (防止调用方遗漏)
        $params += [
            'headExtra' => '',
            'assetBase' => defined('ASSETS_BASE_URL') ? ASSETS_BASE_URL : '',
            'lang' => $params['lang'] ?? 'en',
        ];

        // Auto-inject sidebar from Hooks (module panels)
        if (!isset($params['sidebar']) && class_exists('Converge\Core\Hook\Hooks')) {
            $panels = \Converge\Core\Hook\Hooks::applyFilters('ui.dock.panels', []);
            $params['sidebar'] = $panels;
        }

        // Auto-inject ApiRegistry → window.__API (前端 Action 状态感知)
        if (!isset($params['apiJson']) && class_exists('Converge\Foundation\Contract\ApiRegistry')) {
            $params['apiJson'] = \Converge\Core\Helper\AlpineHelper::encodeForHtml(
                \Converge\Foundation\Contract\ApiRegistry::getAllActions()
            );
        }

        // Auto-inject DataContext → window.__DATA (TDA Data层: 页面级标准化数据)
        if (!isset($params['dataJson'])) {
            try {
                $params['dataJson'] = DataContext::build($params);
            } catch (\Throwable $e) {
                error_log('[LatteEngine] DataContext failed: ' . $e->getMessage());
                $params['dataJson'] = '{}';
            }
        }

        // Auto-inject dockNav config → JSON for _layout.latte Stimulus controllers
        if (!isset($params['dockNavConfig'])) {
            $dockCfg = self::buildDockNavConfigArray($params);
            $params['dockNavConfig'] = json_encode($dockCfg, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG);
            $params['searchIndexJson'] = json_encode($dockCfg['searchIndex'] ?? [], JSON_UNESCAPED_UNICODE);
        }

        // Legacy: inline x-data strings (kept for backward compat with non-Stimulus pages)
        $params['xDataOpen'] = '{ open: false }';
        $params['xDataTz'] = '{ tzOpen: false }';
        $params['xDataNow'] = '{ now: new Date() }';
        $params['xDataNowText'] = sprintf("now.toLocaleTimeString('%s')", $params['lang'] ?? 'en');
        $params['xDataNowInit'] = 'setInterval(function(){ now=new Date() },30000)';

        // 自动包装 HtmlString → Latte 不转义 (契约显式化)
        $params = self::wrapHtmlStrings($params);

        $file = realpath(APP_ROOT . '/templates/' . $template . '.latte');
        if ($file === false) {
            throw new \RuntimeException("Template not found: {$template}");
        }
        return $engine->renderToString($file, $params);
    }

    /**
     * 检查模板是否存�?     */
    public static function exists(string $template): bool
    {
        $file = realpath(APP_ROOT . '/templates/' . $template . '.latte');
        return $file !== false && file_exists($file);
    }

    /**
     * 渲染并直接输出 (适合 include 场景)
     */
    public static function display(string $template, array $params = []): void
    {
        echo self::render($template, $params);
    }

    /**
     * 递归遍历模板参数，将 HtmlString 实例包装为 Latte\Runtime\Html
     * 使模板中 {$var} 自动不转义——无需手工加 |noescape
     */
    private static function wrapHtmlStrings(array $params): array
    {
        foreach ($params as $key => $value) {
            if ($value instanceof \Converge\UI\HtmlString) {
                $params[$key] = new \Latte\Runtime\Html($value->toString());
            } elseif (is_array($value)) {
                $params[$key] = self::wrapHtmlStrings($value);
            }
        }
        return $params;
    }

    /**
     * Build dockNav Alpine.js config as JSON-safe string.
     *
     * Replaces Latte {{ }} template syntax which produces invalid JS (double braces).
     * Each page controller can override via $params['dockNavConfig'].
     */
    private static function buildDockNavConfigArray(array $params): array
    {
        $config = [
            'dock' => $params['dock'] ?? 'tracking',
            'currentPage' => $params['pageName'] ?? 'dashboard',
            'pageLabelMap' => [
                'dashboard' => '仪表盘',
                'campaigns' => '广告活动',
                'reports' => '报告',
                'flow-builder' => '流量路径',
                'conversions' => '转化管理',
                'landing-builder' => 'LP构建器',
                'analytics' => '数据分析',
                'billing' => '账单',
                'settings' => '系统设置',
            ],
            'searchIndex' => [
                ['l' => '仪表盘', 'u' => '/admin-panel.php', 'k' => 'dashboard'],
                ['l' => '广告活动', 'u' => '/campaigns.php', 'k' => 'campaigns'],
                ['l' => '报告分析', 'u' => '/reports.php', 'k' => 'reports'],
                ['l' => '流量路径', 'u' => '/flow-builder.php', 'k' => 'flow'],
                ['l' => 'LP构建器', 'u' => '/landing-builder.php', 'k' => 'landing'],
                ['l' => '转化管理', 'u' => '/conversions.php', 'k' => 'conversions'],
                ['l' => 'Offer管理', 'u' => '/offers.php', 'k' => 'offers'],
                ['l' => '数据分析', 'u' => '/analytics.php', 'k' => 'analytics'],
                ['l' => '访客日志', 'u' => '/visitors.php', 'k' => 'visitors'],
                ['l' => 'Postback', 'u' => '/postback-urls.php', 'k' => 'postback'],
                ['l' => '团队管理', 'u' => '/team.php', 'k' => 'team'],
                ['l' => '账单', 'u' => '/billing.php', 'k' => 'billing'],
                ['l' => '自动规则', 'u' => '/auto-rules.php', 'k' => 'rules'],
                ['l' => '操作日志', 'u' => '/audit-trail.php', 'k' => 'audit'],
                ['l' => '联盟营销', 'u' => '/affiliate-marketing.php', 'k' => 'affiliate'],
                ['l' => 'Webhook', 'u' => '/webhooks.php', 'k' => 'webhooks'],
            ],
        ];
        return $config;
    }

    private static function getEngine(): Engine
    {
        if (self::$engine === null) {
            self::$engine = new Engine();

            // 缓存目录
            $cacheDir = APP_ROOT . '/storage/cache/latte';
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            self::$engine->setTempDirectory($cacheDir);

            // 生产环境关闭自动刷新
            if (getenv('APP_ENV') === 'production') {
                self::$engine->setAutoRefresh(false);
            }

            // 注册自定义过滤器
            self::registerFilters(self::$engine);
        }
        return self::$engine;
    }

    /**
     * 注册自定�?Latte 过滤�?(与现�?PHP 函数对齐)
     */
    private static function registerFilters(Engine $engine): void
    {
        // __() 多语言翻译 �?�?'t' 作为简短别�?(Latte 不允�?__ 作为过滤器名)
        $i18n = fn(string $key) => __($key);
        $engine->addFilter('t', $i18n);
        $engine->addFilter('trans', $i18n);

        // 数字格式�?        $engine->addFilter('number', fn($v, int $decimals = 0) => number_format((float) $v, $decimals));
        $engine->addFilter('dollar', fn($v) => '$' . number_format((float) $v, 2));
        $engine->addFilter('pct', fn($v) => round((float) $v, 2) . '%');
        $engine->addFilter('max', fn(...$args) => max(...$args));
        $engine->addFilter('min', fn(...$args) => min(...$args));

        // 日期
        $engine->addFilter('date', fn(string $v, string $format = 'M d, Y') => date($format, strtotime($v)));

        // HTML 安全转义 (Latte 默认转义，此过滤器标记为"已转义，勿二次转�?)
        $engine->addFilter('raw', fn(string $v) => new \Latte\Runtime\Html($v));

        // jsObj(): Alpine x-data 对象字面量 — 安全输出 { }，配合 |noescape
        // 用法: x-data="{jsObj('open: false')|noescape}" → x-data="{ open: false }"
        $engine->addFunction('jsObj', fn(string $inner): string => '{ ' . $inner . ' }');

        // iconSvg(): emoji → SVG 图标名映射 (用于侧边栏等)
        $engine->addFunction('iconSvg', function (string $emoji): string {
            return match ($emoji) {
                '📊' => 'dashboard',  '📋' => 'campaign',   '🔀' => 'funnel',
                '📈' => 'trending',   '👁️' => 'eye',        '🌍' => 'globe',
                '🛡️' => 'check',      '🤖' => 'code',        '🧪' => 'alert',
                '⚙️' => 'settings',   '💳' => 'billing',     '🔑' => 'copy',
                '👤' => 'user',       '🔗' => 'link',        '📝' => 'edit',
                '➕' => 'plus',       '🗑' => 'trash',       '⏰' => 'clock',
                '💰' => 'billing',    '✅' => 'check',       '❌' => 'x',
                '🚀' => 'external',   '📦' => 'copy',        '🔍' => 'search',
                '📨' => 'link',       '📱' => 'external',    '🎯' => 'campaign',
                default => '',
            };
        });

        // dockMenu(): 从 page-registry.json 读取侧边栏菜单数据
        $engine->addFunction('dockMenu', function (): array {
            $jsonFile = dirname(__DIR__, 4) . '/.claude/reference/page-registry.json';
            if (!file_exists($jsonFile)) return [];
            $data = json_decode(file_get_contents($jsonFile), true);
            return $data['menus'] ?? [];
        });

        // 数据驱动组件: 数据模型决定UI状�?        $engine->addFunction('PricingCard', fn(array $plan): string => \Converge\UI\PricingCard::render($plan));
        $engine->addFunction('Badge', fn(string $text, array $props = []): string => \Converge\UI\Badge::render($text, $props));
        $engine->addFunction('Button', fn(string $text, array $props = []): string => \Converge\UI\Button::render($text, $props));

        // include_php: �?Latte 中渲�?PHP 文件，返回其输出
        $engine->addFunction('include_php', function (string $file): string {
            ob_start();
            require $file;
            return ob_get_clean() ?: '';
        });
    }
}
