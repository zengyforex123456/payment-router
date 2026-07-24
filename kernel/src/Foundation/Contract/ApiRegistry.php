<?php
/**
 * ApiRegistry — 前端 Action → API 端点绑定的唯一真相源
 *
 * 解决: UI 按钮渲染了但后端没实现 → 点击无反应
 * 原理: 每个页面的每个 Action 在此声明其 API 绑定状态。
 *       placeholder → 前端自动置灰 + tooltip "开发中"
 *       implemented → 前端正常交互
 *       deprecated → 前端划线 + tooltip "即将移除"
 *
 * 注入: _layout.latte 中输出 window.__API = ApiRegistry::getAllActions()
 *       前端 Alpine.js 读取 window.__API 渲染按钮状态
 *
 * 用法:
 *   ApiRegistry::getPageActions('analytics')
 *   ApiRegistry::getStatus('analytics', 'export-report')  → 'placeholder'
 */
declare(strict_types=1);

namespace Converge\Foundation\Contract;

class ApiRegistry
{
    /**
     * Action 注册表 — 按页面组织。
     *
     * 新增 Action 只需在此数组中加一条。
     * 实现后端后改 status 为 'implemented'。
     *
     * ⚠️ 这是唯一真相源。不要在前端模板中硬编码 action 状态。
     * 数据在 ApiRegistryData 中 (分离以保持每文件 ≤150 行).
     */
    private static ?array $actions = null;

    private static function loadActions(): array
    {
        if (self::$actions === null) {
            self::$actions = ApiRegistryData::actions();
        }
        return self::$actions;
    }

    // ═══ Public API ═══

    /**
     * 获取指定页面的所有 Action
     * @return array<string, array>
     */
    public static function getPageActions(string $page): array
    {
        return self::loadActions()[$page] ?? [];
    }

    /**
     * 获取所有 Action (扁平化, 注入前端)
     * 输出格式: { "analytics.export-report": { status, endpoint, label }, ... }
     */
    public static function getAllActions(): array
    {
        $flat = [];
        foreach (self::loadActions() as $page => $actions) {
            foreach ($actions as $id => $action) {
                $flat["{$page}.{$id}"] = [
                    'status'   => $action['status'],
                    'label'    => $action['label'],
                    'icon'     => $action['icon'],
                    'trigger'  => $action['trigger'],
                    'endpoint' => $action['endpoint'],
                ];
            }
        }
        return $flat;
    }

    /**
     * 获取单个 Action 的状态
     */
    public static function getStatus(string $page, string $actionId): string
    {
        return self::loadActions()[$page][$actionId]['status'] ?? 'unknown';
    }

    /**
     * 获取指定状态的所有 Action (用于审计)
     * @return array<string, array>
     */
    public static function getByStatus(string $status): array
    {
        $result = [];
        foreach (self::getAllActions() as $key => $action) {
            if ($action['status'] === $status) {
                $result[$key] = $action;
            }
        }
        return $result;
    }

    /**
     * 统计信息 (用于仪表盘)
     */
    public static function stats(): array
    {
        $all = self::getAllActions();
        $total = count($all);
        $implemented = count(self::getByStatus('implemented'));
        $placeholders = count(self::getByStatus('placeholder'));

        return [
            'total'        => $total,
            'implemented'  => $implemented,
            'placeholders' => $placeholders,
            'coverage_pct' => $total > 0 ? round($implemented / $total * 100) : 0,
        ];
    }

    /**
     * 验证 API 端点文件是否存在 (用于 CI 检查)
     * @return array{missing: array, ok: bool}
     */
    public static function verifyEndpoints(): array
    {
        $missing = [];
        foreach (self::getAllActions() as $key => $action) {
            if ($action['status'] !== 'implemented') continue;

            $url = $action['endpoint']['url'];
            // 将 /api/export/report → api/export/report.php
            $file = APP_ROOT . '/public' . $url . '.php';

            if (!file_exists($file)) {
                // Also try api/v1/ pattern
                $altFile = APP_ROOT . '/public/api/v1' . substr($url, 4) . '.php';
                if (!file_exists($altFile)) {
                    $missing[] = "{$key}: expected {$file} (or {$altFile})";
                }
            }
        }
        return ['missing' => $missing, 'ok' => empty($missing)];
    }
}
