<?php
declare(strict_types=1);

namespace Converge\UI;

/**
 * HtmlString — 显式标记"这是已受控的 HTML，模板层应信任并直接渲染"
 *
 * 使用场景:
 *   - 硬编码营销文案 (landing.php)
 *   - 经过 HTMLPurifier 净化的管理员内容
 *   - 组件 ::render() 返回的预编译 HTML
 *
 * 禁止场景:
 *   - 任何来自用户输入且未经 HTMLPurifier 净化的数据
 *
 * LatteEngine 自动检测: 遇到 HtmlString 实例 → 不转义直接输出
 *
 * 用法:
 *   'desc' => new HtmlString('<strong>痛点：</strong>退款发生...'),
 *   'desc' => HtmlString::fromTrustedString('<strong>可信</strong>'),
 *   'desc' => HtmlString::fromPurified($purifier->purify($userInput)),
 */
final class HtmlString implements \Stringable
{
    private string $html;

    private function __construct(string $html)
    {
        $this->html = $html;
    }

    /**
     * 从已知可信用字符串创建 (硬编码文案 / 组件输出)
     */
    public static function fromTrustedString(string $html): self
    {
        return new self($html);
    }

    /**
     * 从 HTMLPurifier 净化后的字符串创建 (用户输入)
     */
    public static function fromPurified(string $purifiedHtml): self
    {
        return new self($purifiedHtml);
    }

    /** 别名，语义更清晰的命名 */
    public static function trusted(string $html): self
    {
        return self::fromTrustedString($html);
    }

    public function toString(): string
    {
        return $this->html;
    }

    public function __toString(): string
    {
        return $this->html;
    }

    /** 追加可信 HTML */
    public function append(self|string $other): self
    {
        return new self($this->html . (string)$other);
    }
}
