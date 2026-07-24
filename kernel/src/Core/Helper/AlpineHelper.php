<?php
declare(strict_types=1);

namespace Converge\Core\Helper;

/**
 * AlpineHelper — 安全输出 JSON 到 Alpine.js x-data 表达式
 *
 * 设计原则:
 *   x-data 属性用单引号 → 内嵌 JSON 可以自由使用双引号
 *   JSON_HEX_APOS: 值中的单引号 → ' (不破坏单引号属性)
 *   JSON_HEX_TAG + JSON_HEX_AMP: 防止 XSS
 *   不用 htmlspecialchars — 不需要 HTML 实体, Alpine 直接解析 JSON
 *
 * 用法:
 *   <div x-data='dockNav(<?=AlpineHelper::encode($data)?>)'>
 *
 * @see scripts/validate-alpine-xdata.php — 自动化验证
 */
class AlpineHelper
{
    /**
     * 将 PHP 数据编码为 Alpine x-data 安全的 JSON
     *
     * 标志:
     *   JSON_UNESCAPED_SLASHES  — A/B测试 不变 A\/B测试
     *   JSON_UNESCAPED_UNICODE   — 中文不转 \uXXXX (Alpine 需要原生中文)
     *   JSON_HEX_APOS            — ' → ' (单引号属性安全)
     *   JSON_HEX_TAG             — < > → < > (XSS 防御)
     *   JSON_HEX_AMP             — & → & (HTML 实体安全)
     *
     * 注意: 不使用 JSON_HEX_QUOT — 保留原生 " 让 Alpine 的 JS 解析器直接识别
     *
     * @param mixed $data
     * @return string JSON 字符串
     * @throws \RuntimeException 编码失败时
     */
    public static function encode($data): string
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
            JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP
        );

        if ($json === false) {
            throw new \RuntimeException(
                'Alpine JSON 编码失败: ' . json_last_error_msg()
            );
        }

        return $json;
    }

    /**
     * 编码为 Alpine 表达式安全的 JSON (不含引号包裹)
     *
     * 用法:
     *   <div x-data='dockNav(<?=AlpineHelper::encodeForHtml($data)?>)'>
     *   <div x-data='<?=AlpineHelper::encodeForHtml($data)?>'>
     *
     * @param mixed $data
     * @return string 纯 JSON 字符串 (双引号定界, 值中 ' < > & 已转义)
     */
    public static function encodeForHtml($data): string
    {
        return self::encode($data);
    }

    /**
     * 验证 JSON 是否可用于 Alpine 表达式
     *
     * @param string $json
     * @return array{valid: bool, error: string|null}
     */
    public static function validate(string $json): array
    {
        // 1. 可反序列化
        $decoded = json_decode($json, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return ['valid' => false, 'error' => 'JSON 不可解析: ' . json_last_error_msg()];
        }

        // 2. 无转义斜杠
        if (strpos($json, '\\/') !== false) {
            return ['valid' => false, 'error' => 'JSON 包含转义斜杠 \\/ — 需 JSON_UNESCAPED_SLASHES'];
        }

        return ['valid' => true, 'error' => null];
    }
}
