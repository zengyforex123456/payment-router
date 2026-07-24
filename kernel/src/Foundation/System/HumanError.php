<?php

declare(strict_types=1);

namespace Converge\Foundation\System;

/**
 * HumanError — 把技术错误翻译成人话
 *
 * 第一层原则: 用户看到报错后, 知道"我该做什么"而不是"这是什么鬼"
 */
class HumanError
{
    /** @var array<string, string> 正则 → 人话 */
    private const MAP = [
        // MySQL
        '/Duplicate entry.*for key.*(\w+)/i'
            => '这个名字已经被用了，换一个。',
        '/Cannot delete.*a foreign key constraint/i'
            => '还有别的东西在用这个，先把它们删掉或换掉。',
        '/Data too long for column/i'
            => '输入太长了，缩短一些。',
        '/Column.*cannot be null/i'
            => '这个字段不能空着，填点东西。',

        // SQLite
        '/SQLITE_CONSTRAINT.*UNIQUE/i'
            => '已经有一个同名的了，换一个名字。',
        '/SQLITE_ERROR.*no such column/i'
            => '数据库结构不匹配，联系管理员升级。',

        // Connection
        '/Connection refused/i'
            => '服务器暂时连不上，等几秒刷新就好。',
        '/Connection timed out/i'
            => '服务器响应太慢，检查网络后重试。',
        '/Too many connections/i'
            => '现在访问的人太多了，等一下再试。',

        // Network
        '/cURL error.*(6|7|28)/i'
            => '网络请求失败，检查网络连接后重试。',

        // Auth
        '/Access denied/i'
            => '你没有权限做这个操作，找管理员开通。',
        '/Session expired/i'
            => '登录已过期，刷新页面重新登录。',

        // API
        '/401 Unauthorized/i'
            => 'API Key 不对，检查后重试。',
        '/429 Too Many Requests/i'
            => '请求太频繁了，等一分钟再试。',

        // File
        '/Permission denied/i'
            => '没有文件写入权限，检查目录权限。',
        '/No such file/i'
            => '找不到需要的文件，可能被删了。',
        '/Disk full/i'
            => '磁盘空间满了，清理一些空间。',

        // Validation
        '/is required/i'
            => '这个字段必须填。',
        '/Invalid email/i'
            => '邮箱格式不对。',
        '/must be a valid URL/i'
            => 'URL 格式不对，检查是否漏了 http(s)://',
    ];

    /**
     * 翻译错误消息。
     * 匹配规则 → 返回人话；未匹配 → 返回原始消息。
     */
    public static function translate(string $message): string
    {
        foreach (self::MAP as $pattern => $human) {
            if (preg_match($pattern, $message)) {
                return $human;
            }
        }
        return $message;
    }

    /**
     * 从 Throwable 翻译。
     */
    public static function from(\Throwable $e): string
    {
        return self::translate($e->getMessage());
    }

    /**
     * 翻译并包裹为可展示的 HTML。
     */
    public static function html(string $message, string $type = 'error'): string
    {
        $text = self::translate($message);
        $class = $type === 'error' ? 'alert alert-danger' : 'alert alert-warning';
        return "<div class=\"{$class}\">" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "</div>";
    }
}
