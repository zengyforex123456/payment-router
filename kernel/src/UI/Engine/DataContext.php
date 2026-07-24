<?php
/**
 * DataContext — TDA 数据层核心: 页面级标准化数据注入 (D: Data 层)
 *
 * 生成 `window.__DATA` 对象, 包含:
 *   page, user, menu, actions, locale, csrf
 *
 * 页面级数据通过 window.__DATA 共享, 组件级数据通过
 * <script type="application/json" x-ref="options"> 隔离传递.
 *
 * 用法 (LatteEngine 自动调用):
 *   $__dataJson = DataContext::build($params);
 *   // → 注入 $params['__dataJson'], _layout.latte 输出 window.__DATA
 */
declare(strict_types=1);

namespace Converge\UI\Engine;

use Converge\UI\ViewContext;
use Converge\Core\Helper\AlpineHelper;

class DataContext
{
    /**
     * 构建标准化 __DATA 对象.
     *
     * @param array $params LatteEngine render() 的完整参数
     * @return string JSON 字符串 (已安全编码, 可 |noescape 输出)
     */
    public static function build(array $params): string
    {
        $context = $params['context'] ?? null;
        $user = $params['user'] ?? [];

        $data = [
            'page'    => $params['__pageName'] ?? 'unknown',
            'user'    => [
                'id'       => $user['id'] ?? 0,
                'username' => $user['username'] ?? '',
                'email'    => $user['email'] ?? '',
                'role'     => $user['role'] ?? 'user',
            ],
            'locale'  => $params['lang'] ?? 'en',
            'csrf'    => self::getCsrfToken(),
            'actions' => self::getActions(),
            'menu'    => $params['sidebar'] ?? [],
        ];

        // 注入当前页面特有数据 (由控制器通过 $params 传入)
        if (isset($params['__pageData'])) {
            $data['pageData'] = $params['__pageData'];
        }

        return AlpineHelper::encodeForHtml($data);
    }

    /** 获取当前 CSRF token */
    private static function getCsrfToken(): string
    {
        if (class_exists('Converge\Security\Csrf')) {
            try { return \Converge\Security\Csrf::token(); } catch (\Throwable) {}
        }
        return '';
    }

    /** 获取当前用户的动作权限摘要 */
    private static function getActions(): array
    {
        if (class_exists('Converge\Foundation\Contract\ApiRegistry')) {
            try { return \Converge\Foundation\Contract\ApiRegistry::getAllActions(); } catch (\Throwable) {}
        }
        return [];
    }
}
