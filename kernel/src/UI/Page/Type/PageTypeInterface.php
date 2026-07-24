<?php
declare(strict_types=1);

namespace Converge\UI\Page\Type;

/**
 * PageTypeInterface — 页面类型契约
 *
 * 每种页面类型（List/Detail/Form/Custom）提供不同的布局骨架和交互模式。
 * PageRenderer 在渲染完所有区块后，将内容委托给页面类型进行外层包裹。
 *
 * 与 Template Wrapper 的区别:
 *   - Wrapper = 页面级容器（default/full-width/blank），只管宽度
 *   - PageType = 业务布局骨架（header+search+table+pagination），管交互模式
 */
interface PageTypeInterface
{
    /**
     * 用页面类型布局包裹区块内容
     *
     * @param string $blockHtml 已渲染的区块 HTML
     * @param array  $page      页面定义（title, dataSource, ...）
     * @param array  $pageData  页面级数据源查询结果
     * @return string 完整页面 HTML
     */
    public function wrap(string $blockHtml, array $page, array $pageData): string;

    /** 页面类型标识: 'list' | 'detail' | 'form' */
    public static function type(): string;
}
