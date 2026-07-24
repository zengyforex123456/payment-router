<?php
declare(strict_types=1);
namespace Converge\UI\Blocks;

use Converge\UI\Page\PageRenderer;

/**
 * Composition — Atomic Layout 布局蓝图 (Composition Layer)
 *
 * 将页面结构定义为"命名区域 + 插槽"而非硬编码 HTML。
 * 与 Atomic Layout 的 <Composition areas="header|main"> 概念一致。
 *
 * 用法 (JSON):
 *   {
 *     "type": "composition",
 *     "props": {
 *       "areas": "header header | sidebar main | footer footer",
 *       "columns": "240px 1fr",
 *       "gap": "md"
 *     },
 *     "slots": {
 *       "header": [{"type":"heading", ...}],
 *       "sidebar": [{...}],
 *       "main": [{...}],
 *       "footer": [{...}]
 *     }
 *   }
 *
 * Props:
 *   areas: string — CSS Grid areas (| 分行, 空格分列)
 *   columns: string — 列宽 (如 "240px 1fr", "1fr 1fr 1fr")
 *   rows: string — 行高 (如 "auto 1fr auto")
 *   gap: 'sm'|'md'|'lg'|'xl'
 *   slots: array — 命名插槽 → 子区块列表
 *   responsive: array — 断点覆盖 (如 {"sm": {"areas": "..."}})
 */
class Composition
{
    private const GAP_MAP = [
        'none' => '0',
        'sm'   => '8px',
        'md'   => '16px',
        'lg'   => '24px',
        'xl'   => '32px',
    ];
    private const GAP_FALLBACK = '16px';

    private PageRenderer $renderer;

    public function __construct(?PageRenderer $renderer = null)
    {
        $this->renderer = $renderer ?? new PageRenderer();
    }

    /**
     * 渲染布局蓝图：areas → CSS Grid + 递归渲染 slots 中的子区块
     */
    public static function render(array $props = []): string
    {
        $comp = new self();
        return $comp->renderComposition($props);
    }

    public function renderComposition(array $props): string
    {
        $areas = $props['areas'] ?? 'main';
        $columns = $props['columns'] ?? '1fr';
        $gapKey = $props['gap'] ?? 'md';
        $gap = self::GAP_MAP[$gapKey] ?? self::GAP_FALLBACK;
        $slots = $props['slots'] ?? [];
        $responsive = $props['responsive'] ?? [];

        // 解析 areas → CSS grid-template-areas
        $areaLines = array_map('trim', explode('|', $areas));

        // 动态计算行数: 如果未指定 rows，根据 areas 行数自动生成
        $rows = $props['rows'] ?? null;
        if (!$rows) {
            $rowCount = count($areaLines);
            $rows = implode(' ', array_fill(0, $rowCount, 'auto'));
        }
        // 单引号包裹区域名，避免 HTML style 属性中的双引号截断
        $gridAreas = array_map(function(string $line): string {
            $cells = array_map('trim', explode(' ', $line));
            return "'" . implode(' ', $cells) . "'";
        }, $areaLines);
        $templateAreas = implode(' ', $gridAreas);

        // 收集所有唯一区域名
        $allNames = [];
        foreach ($areaLines as $line) {
            foreach (explode(' ', $line) as $name) {
                $allNames[trim($name)] = true;
            }
        }
        $uniqueAreas = array_keys($allNames);

        // 构建内联样式
        $style = "display:grid;";
        $style .= "grid-template-areas:{$templateAreas};";
        $style .= "grid-template-columns:{$columns};";
        $style .= "grid-template-rows:{$rows};";
        $style .= "row-gap:{$gap};column-gap:{$gap};";
        $style .= "container-type:inline-size;";

        // 渲染每个 slot 的子区块
        $slotHtml = [];
        foreach ($uniqueAreas as $name) {
            $areaBlocks = $slots[$name] ?? [];
            if (empty($areaBlocks)) continue;

            $rendered = '';
            foreach ($areaBlocks as $block) {
                $rendered .= $this->renderer->renderBlockSingle($block);
            }

            // 用 Stack 包裹多区块区域（保持子元素间距一致）
            if (count($areaBlocks) > 1) {
                $rendered = '<div style="display:flex;flex-direction:column;gap:' . $gap . '">' . $rendered . '</div>';
            }

            $slotHtml[$name] = $rendered;
        }

        // 生成 HTML：每个 slot 对应一个 grid-area 的 div
        $html = "<div class=\"composition\" style=\"{$style}\">";
        foreach ($uniqueAreas as $name) {
            $content = $slotHtml[$name] ?? '';
            $html .= "<div style=\"grid-area:{$name};min-width:0\">{$content}</div>";
        }
        $html .= '</div>';

        // 响应式覆写（注入 <style> 标签）
        if ($responsive) {
            $html .= '<style>';
            foreach ($responsive as $bp => $overrides) {
                $maxW = ['sm' => '640px', 'md' => '768px', 'lg' => '1024px'][$bp] ?? $bp;
                $css = '';
                if (isset($overrides['areas'])) {
                    $rAreas = array_map('trim', explode('|', $overrides['areas']));
                    $rGridAreas = array_map(
                        fn(string $line) => '"' . implode(' ', array_map('trim', explode(' ', $line))) . '"',
                        $rAreas
                    );
                    $css .= "grid-template-areas:" . implode(' ', $rGridAreas) . ";";
                }
                if (isset($overrides['columns'])) {
                    $css .= "grid-template-columns:{$overrides['columns']};";
                }
                if ($css) {
                    $html .= "@container (max-width:{$maxW}){.composition{{$css}}}";
                }
            }
            $html .= '</style>';
        }

        return $html;
    }
}
