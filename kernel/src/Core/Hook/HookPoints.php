<?php

declare(strict_types=1);

namespace Converge\Core\Hook;

/**
 * HookPoints — Converge 预定义钩子注册表
 *
 * 所有模块通过此文件中的钩子点接入核心流程，不修改核心代码。
 * 对标 WordPress 内置 Hook 体系。
 *
 * 用法:
 *   Hooks::addAction(HookPoints::CLICK_TRACKED, fn($data) => $kag->log($data));
 *   Hooks::addFilter(HookPoints::CAMPAIGN_URL, fn($url) => ensureHttps($url));
 */

/**
 * Hook 优先级规范:
 *
 *   1-5:   系统关键 (核心初始化、安全校验)
 *   10:    默认 (大多数模块在这里)
 *   15-20: 后处理 (日志、分析、通知)
 *   50+:   最后执行 (KAG 记录、指标上报)
 *
 *   同一 Hook 多模块: 高优先级(小数字)先执行
 *   最佳实践: 不了解优先级就用 10，不要随便用 1
 */
class HookPoints
{
    // ═══ 生命周期 (Actions) ═══

    /** 系统启动完成，所有模块已加载。模块在此初始化 */
    public const MODULE_INIT = 'module.init';

    /** 请求开始前触发 */
    public const REQUEST_START = 'request.start';

    /** 请求结束后触发 */
    public const REQUEST_END = 'request.end';

    // ═══ 追踪事件 (Actions) ═══

    /** 点击被追踪时触发 */
    public const CLICK_TRACKED = 'click.tracked';

    /** 转化被记录时触发 */
    public const CONVERSION_RECORDED = 'conversion.recorded';

    /** 回传发送成功时触发 */
    public const POSTBACK_SENT = 'postback.sent';

    /** 回传发送失败时触发 */
    public const POSTBACK_FAILED = 'postback.failed';

    /** 用户注册时触发 */
    public const USER_REGISTERED = 'user.registered';

    /** 用户登录时触发 */
    public const USER_LOGGED_IN = 'user.logged_in';

    /** Campaign 创建时触发 */
    public const CAMPAIGN_CREATED = 'campaign.created';

    /** Campaign 状态变更时触发 */
    public const CAMPAIGN_STATUS_CHANGED = 'campaign.status_changed';

    /** 部署完成时触发 */
    public const DEPLOY_COMPLETED = 'deploy.completed';

    /** 金丝雀验证通过时触发 */
    public const CANARY_PASSED = 'canary.passed';

    /** 金丝雀失败回滚时触发 */
    public const CANARY_FAILED = 'canary.failed';

    /** 错误被捕获时触发 */
    public const ERROR_CAPTURED = 'error.captured';

    // ═══ 数据修改 (Filters) ═══

    /** 修改 Campaign 追踪 URL */
    public const CAMPAIGN_URL = 'campaign.url';

    /** 修改回传请求体 (发送前) */
    public const POSTBACK_PAYLOAD = 'postback.payload';

    /** 修改用户注册数据 */
    public const USER_REGISTRATION_DATA = 'user.registration_data';

    /** 修改页面 HTML 输出 */
    public const PAGE_OUTPUT = 'page.output';

    /** 修改 API 响应 */
    public const API_RESPONSE = 'api.response';

    /** 修改邮件内容 */
    public const EMAIL_CONTENT = 'email.content';

    /** 修改 KAG 知识条目 (存储前) */
    public const KAG_ENTITY = 'kag.entity';

    // ═══ 内置 Hook 注册 (核心功能通过 Hook 实现, 不硬编码) ═══

    /**
     * 注册所有内置 Hook 处理器
     * 在 bootstrap.php 中调用一次即可
     */
    public static function registerBuiltin(): void
    {
        // 触发模块初始化 (在所有模块 bootstrap.php 加载之后)
        // 这个调用放在此处，bootloader 在 require 完所有模块后手动调用
        // Hooks::doAction(self::MODULE_INIT);

        // KAG: 每次点击自动记录
        Hooks::addAction(self::CLICK_TRACKED, function ($data) {
            if (class_exists('Converge\\Knowledge\\KagClient') && defined('DB_HOST')) {
                try {
                    $db = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
                    $kag = new \Converge\Knowledge\KagClient($db);
                    $kag->logError('click', json_encode($data, JSON_UNESCAPED_UNICODE), 'click_tracked');
                } catch (\Throwable $e) { error_log(__CLASS__ . ": " . $e->getMessage()); }
            }
        });

        // 错误捕获 → KAG
        Hooks::addAction(self::ERROR_CAPTURED, function ($data) {
            if (class_exists('Converge\\Knowledge\\KagClient') && defined('DB_HOST')) {
                try {
                    $db = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
                    $kag = new \Converge\Knowledge\KagClient($db);
                    $kag->fingerprintError($data['source'] ?? 'unknown', $data['message'] ?? '');
                } catch (\Throwable $e) { error_log(__CLASS__ . ": " . $e->getMessage()); }
            }
        });

        // 部署完成 → KAG 记录
        Hooks::addAction(self::DEPLOY_COMPLETED, function () {
            if (class_exists('Converge\\Knowledge\\KagClient') && defined('DB_HOST')) {
                try {
                    $db = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
                    $kag = new \Converge\Knowledge\KagClient($db);
                    $kag->capture('部署记录', 'Deploy completed', [
                        'time' => date('c'),
                        'git' => trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?: '?'),
                    ], ['deploy'], 'seed');
                } catch (\Throwable $e) { error_log(__CLASS__ . ": " . $e->getMessage()); }
            }
        });

        // 页面输出: 注入安全头
        Hooks::addFilter(self::PAGE_OUTPUT, function ($html) {
            if (is_string($html) && !str_contains($html, 'X-Content-Type-Options')) {
                // Nonce 安全注入点 (未来扩展)
            }
            return $html;
        });
    }
}
