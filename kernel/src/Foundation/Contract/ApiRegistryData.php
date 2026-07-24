<?php
/**
 * ApiRegistryData — Action 注册表数据 (从 ApiRegistry 分离, 保持每文件 ≤150 行)
 *
 * 新增 Action: 只修改此文件, 不加到 ApiRegistry.php.
 * 实现后端后改 status 为 'implemented'.
 */
declare(strict_types=1);

namespace Converge\Foundation\Contract;

class ApiRegistryData
{
    public static function actions(): array
    {
        return [

            // ═══ Analytics ═══
            'analytics' => [
                'export-report' => [
                    'label'    => 'Export Report',
                    'icon'     => '📊',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/export/report', 'method' => 'POST'],
                    'status'   => 'implemented',
                ],
                'schedule-email' => [
                    'label'    => 'Schedule Email',
                    'icon'     => '📧',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/email/schedule', 'method' => 'POST'],
                    'status'   => 'implemented',
                ],
                'set-alert' => [
                    'label'    => 'Set Alert',
                    'icon'     => '🔔',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/alerts/create', 'method' => 'POST'],
                    'status'   => 'implemented',
                ],
            ],

            // ═══ Dashboard ═══
            'dashboard' => [
                'refresh-stats' => [
                    'label'    => 'Refresh',
                    'icon'     => '🔄',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/stats/dashboard', 'method' => 'GET'],
                    'status'   => 'implemented',
                ],
                'export-csv' => [
                    'label'    => 'Export CSV',
                    'icon'     => '📥',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/export/dashboard', 'method' => 'POST'],
                    'status'   => 'implemented',
                ],
            ],

            // ═══ Campaigns ═══
            'campaigns' => [
                'create-campaign' => [
                    'label'    => 'Create Campaign',
                    'icon'     => '➕',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/campaigns/create', 'method' => 'POST'],
                    'status'   => 'implemented',
                ],
                'edit-campaign' => [
                    'label'    => 'Edit',
                    'icon'     => '✏️',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/campaigns/update', 'method' => 'POST'],
                    'status'   => 'implemented',
                ],
                'delete-campaign' => [
                    'label'    => 'Delete',
                    'icon'     => '🗑️',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/campaigns/delete', 'method' => 'POST'],
                    'status'   => 'implemented',
                ],
                'toggle-campaign' => [
                    'label'    => 'Toggle Active',
                    'icon'     => '🔘',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/campaigns/toggle', 'method' => 'POST'],
                    'status'   => 'implemented',
                ],
            ],

            // ═══ Flow Builder ═══
            'flow-builder' => [
                'save-flow' => [
                    'label'    => 'Save Flow',
                    'icon'     => '💾',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/flows/save', 'method' => 'POST'],
                    'status'   => 'implemented',
                ],
                'test-flow' => [
                    'label'    => 'Test Flow',
                    'icon'     => '🧪',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/flows/test', 'method' => 'POST'],
                    'status'   => 'implemented',
                ],
            ],

            // ═══ Reports ═══
            'reports' => [
                'export-csv' => [
                    'label'    => 'Export CSV',
                    'icon'     => '📥',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/reports/export', 'method' => 'POST'],
                    'status'   => 'implemented',
                ],
                'export-pdf' => [
                    'label'    => 'Export PDF',
                    'icon'     => '📄',
                    'trigger'  => 'click',
                    'endpoint' => ['url' => '/api/reports/export-pdf', 'method' => 'POST'],
                    'status'   => 'implemented',
                ],
            ],
        ];
    }
}
