<?php

declare(strict_types=1);

namespace Converge\UI\Molecules;

/**
 * DataField — 动态字段编辑器
 *
 * 根据字段类型渲染对应的输入控件。
 * 用于 Section 数据编辑表单，支持 text / textarea / url / email / richtext。
 *
 * 用法: DataField::render('title', 'Hello World', 'text', '标题')
 */
final class DataField
{
    /**
     * @param string $key 字段键名
     * @param mixed $value 当前值
     * @param string $type text|textarea|url|email|richtext
     * @param string|null $label 显示标签
     */
    public static function render(
        string $key,
        mixed $value,
        string $type = 'text',
        ?string $label = null,
    ): string {
        $label ??= ucfirst($key);
        $safeValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $safeKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        $input = match ($type) {
            'textarea' => sprintf(
                '<textarea name="%s" rows="3" class="input" x-model="fields.%s">%s</textarea>',
                $safeKey, $safeKey, $safeValue,
            ),
            'richtext' => sprintf(
                '<textarea name="%s" rows="4" class="input" x-model="fields.%s">%s</textarea>'
                . '<small class="text-xs text-content-tertiary">Supports &lt;strong&gt; and &lt;br&gt;</small>',
                $safeKey, $safeKey, $safeValue,
            ),
            'url' => sprintf(
                '<input type="url" name="%s" class="input" x-model="fields.%s" value="%s">',
                $safeKey, $safeKey, $safeValue,
            ),
            'email' => sprintf(
                '<input type="email" name="%s" class="input" x-model="fields.%s" value="%s">',
                $safeKey, $safeKey, $safeValue,
            ),
            default => sprintf(
                '<input type="text" name="%s" class="input" x-model="fields.%s" value="%s">',
                $safeKey, $safeKey, $safeValue,
            ),
        };

        return sprintf(
            '<div class="form-group mb-sm"><label class="text-xs font-bold text-content-tertiary uppercase" for="%s">%s</label>%s</div>',
            $safeKey, $safeLabel, $input,
        );
    }
}
