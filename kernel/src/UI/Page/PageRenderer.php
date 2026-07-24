<?php
declare(strict_types=1);
namespace Converge\UI\Page;

use Converge\UI\RenderContext;
use Converge\UI\ErrorBoundary;
use Converge\UI\Data\DataSourceRegistry;
use Converge\UI\Verify\BlockContractValidator;
use Converge\Core\Hook\Hooks;

/**
 * PageRenderer — JSON 渲染完整页面
 * 数据源: data/pages/{slug}.json
 *
 * 支持:
 *   - RenderContext: 用户/租户/权限上下文
 *   - 区块嵌套 (children): Grid 容器包含子区块
 *   - 页面类型 (pageType): list | detail | form | custom
 *   - 页面级数据源 (dataSource): 整页绑定数据
 */
class PageRenderer
{
    private string $pagesDir;

    public function __construct(?string $pagesDir = null) {
        $this->pagesDir = $pagesDir ?? dirname(__DIR__, 3) . '/data/pages';
    }

    public function listPages(): array {
        $pages = [];
        foreach (glob($this->pagesDir . '/*.json') as $f) {
            $data = json_decode(file_get_contents($f), true);
            if ($data) $pages[] = ['slug' => basename($f, '.json'), 'title' => $data['title'] ?? basename($f, '.json')];
        }
        return $pages;
    }

    public function save(string $slug, array $definition): bool {
        $file = $this->pagesDir . '/' . preg_replace('/[^a-z0-9-]/', '', $slug) . '.json';
        return (bool)file_put_contents($file, json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 直接从 blocks 数组渲染预览（不写磁盘）
     * 用于 Builder 即时预览: POST blocks → HTML → iframe srcdoc
     *
     * @param array<int, array> $blocks 区块定义 [{type, props}, ...]
     * @param RenderContext|null $ctx
     */
    public function renderPreview(array $blocks, ?RenderContext $ctx = null): string {
        $ctx = $ctx ?? RenderContext::anonymous();
        $ctx->enter();
        try {
            $page = ['title' => 'Preview', 'template' => 'blank', 'blocks' => $blocks];
            return $this->renderLayout($page);
        } finally {
            $ctx->exit();
        }
    }

    /**
     * 渲染页面
     * @param string            $slug 页面标识
     * @param RenderContext|null $ctx  渲染上下文（用户/租户/权限），null=匿名访问
     */
    public function render(string $slug, ?RenderContext $ctx = null): string {
        $file = $this->pagesDir . '/' . $slug . '.json';
        if (!file_exists($file)) return $this->notFound($slug);
        $page = json_decode(file_get_contents($file), true);
        if (!$page) return $this->notFound($slug);

        // 进入渲染上下文 — 所有 Block 和 DataSource 自动可用
        $ctx = $ctx ?? RenderContext::anonymous();
        $ctx->enter();
        try {
            $html = $this->renderLayout($page);
        } finally {
            $ctx->exit();
        }
        return $html;
    }

    /** @var array<string, class-string> */
    private array $pageTypes = [
        'list'   => \Converge\UI\Page\Type\ListPageType::class,
        'detail' => \Converge\UI\Page\Type\DetailPageType::class,
        'form'   => \Converge\UI\Page\Type\FormPageType::class,
    ];

    private function renderLayout(array $page): string {
        // === 页面级权限门禁 ===
        $ctx = RenderContext::current();
        $pageRole = $page['requiredRole'] ?? '';
        $pagePerm = $page['requiredPermission'] ?? '';
        if ($pageRole && $ctx && !$ctx->hasRole($pageRole)) {
            return $this->forbidden('role: ' . $pageRole);
        }
        if ($pagePerm && $ctx && !$ctx->can($pagePerm)) {
            return $this->forbidden('permission: ' . $pagePerm);
        }

        // ── 布局蓝图 (Composition Layer) ──
        // 当 page.json 包含 layout 字段时，使用 Atomic Layout 命名区域模式
        // layout: { type: "composition", props: { areas: "header|main", ... }, slots: {...} }
        $layout = $page['layout'] ?? null;
        if ($layout && ($layout['type'] ?? '') === 'composition') {
            $composition = new \Converge\UI\Blocks\Composition($this);
            $compProps = $layout['props'] ?? [];
            $compProps['slots'] = $layout['slots'] ?? [];
            $blockHtml = $composition->renderComposition($compProps);
        } else {
            // Fallback: 传统线性 blocks 列表
            $blocks = $page['blocks'] ?? [];
            $blockHtml = '';
            foreach ($blocks as $block) {
                $blockHtml .= $this->renderBlock($block);
            }
        }

        // 页面级数据源
        $pageData = $this->fetchPageData($page);

        // 页面类型布局（list/detail/form 优先于 template wrapper）
        $pageType = $page['pageType'] ?? '';
        if ($pageType && isset($this->pageTypes[$pageType])) {
            $typeClass = $this->pageTypes[$pageType];
            $instance = new $typeClass();
            return $instance->wrap($blockHtml, $page, $pageData);
        }

        // Fallback: template wrapper (default | full-width | blank)
        $template = $page['template'] ?? 'default';
        $wrappers = [
            'default'    => 'Converge\\UI\\Page\\Wrapper\\DefaultWrapper',
            'full-width' => 'Converge\\UI\\Page\\Wrapper\\FullWidthWrapper',
            'blank'      => 'Converge\\UI\\Page\\Wrapper\\BlankWrapper',
        ];
        $wrapperClass = $wrappers[$template] ?? $wrappers['default'];
        return $wrapperClass::wrap($blockHtml, $page);
    }

    /**
     * 解析页面级数据源
     * @return array<int, array<string, mixed>>
     */
    private function fetchPageData(array $page): array
    {
        $dsName = $page['dataSource'] ?? '';
        if (!$dsName) return [];

        $source = DataSourceRegistry::resolve($dsName);
        if (!$source) return [];

        return $source->fetch($page['dataSourceParams'] ?? [], RenderContext::current());
    }

    /** 最大嵌套深度，防止循环引用导致栈溢出 */
    private const MAX_NEST_DEPTH = 8;

    /**
     * 递归渲染区块（支持 children 嵌套）
     * @param array $block 区块定义 {type, props, children?}
     * @param int   $depth 当前嵌套深度
     */
    /** 公开入口：渲染单个 Block（供 Composition 等容器调用） */
    public function renderBlockSingle(array $block): string {
        return $this->renderBlock($block, 0);
    }

    private function renderBlock(array $block, int $depth = 0): string {
        if ($depth >= self::MAX_NEST_DEPTH) {
            return '<!-- Block nested too deep: ' . ($block['type'] ?? '?') . ' -->';
        }

        $type = $block['type'] ?? 'paragraph';
        $props = $block['props'] ?? [];

        // === 运行时合同验证 (dev 模式) ===
        BlockContractValidator::validate($type, $props);

        // === 权限门禁 ===
        $ctx = RenderContext::current();
        $requiredRole = $props['requiredRole'] ?? '';
        $requiredPerm = $props['requiredPermission'] ?? '';
        if ($requiredRole && $ctx && !$ctx->hasRole($requiredRole)) {
            return ''; // 静默隐藏，不报错
        }
        if ($requiredPerm && $ctx && !$ctx->can($requiredPerm)) {
            return '';
        }

        // 递归渲染子区块（容器区块核心机制）
        if (!empty($props['children']) && is_array($props['children'])) {
            $rendered = '';
            foreach ($props['children'] as $child) {
                $rendered .= $this->renderBlock($child, $depth + 1);
            }
            $props['children'] = $rendered;
        }

        // Block resolution via registry (auto-discovered, not hardcoded)
        $class = BlockRegistry::resolve($type);
        if ($class && method_exists($class, 'render')) {
            // Lifecycle: before
            Hooks::doAction('block.before_render', ['type' => $type, 'props' => $props]);

            // Render with error boundary — one block crash won't break the page
            $html = ErrorBoundary::wrap(
                fn() => $class::render($props),
                $type
            );

            // Lifecycle: after
            Hooks::doAction('block.after_render', ['type' => $type, 'html_len' => strlen($html)]);

            return $html;
        }

        // Fallback
        $text = htmlspecialchars($props['text'] ?? $props['content'] ?? '', ENT_QUOTES);
        return "<p class=\"text-content-secondary\">{$text}</p>";
    }

    private function forbidden(string $reason): string {
        return "<!DOCTYPE html><html><head><title>403</title></head><body style=\"text-align:center;padding:100px;font-family:sans-serif\"><h1>403</h1><p>Access denied — {$reason}</p><a href=\"/\">Back</a></body></html>";
    }

    private function notFound(string $slug): string {
        $slug = htmlspecialchars($slug, ENT_QUOTES);
        return "<!DOCTYPE html><html><head><title>404</title></head><body style=\"text-align:center;padding:100px;font-family:sans-serif\"><h1>404</h1><p>Page '{$slug}' not found.</p><a href=\"/builder.php\">Create it in Builder</a></body></html>";
    }
}
