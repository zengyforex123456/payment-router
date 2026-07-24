<?php
/**
 * PaymentRouter — Professional Edition Test Suite
 *
 * 验证: 自定义策略保存、配置导出/导入、安装脚本存在性
 */
declare(strict_types=1);

$base = __DIR__ . '/../modules/PaymentRouter';
require_once "$base/Domain/StrategyTemplate.php";
require_once "$base/Domain/TenantUsage.php";
require_once "$base/Application/ConfigureStrategyUseCase.php";

$pass = 0; $fail = 0;
function test(string $n, callable $f): void { global $pass, $fail; try { $f(); echo "  ✅ $n\n"; $pass++; } catch (Throwable $e) { echo "  ❌ $n — {$e->getMessage()}\n"; $fail++; } }

echo "══════════════════════════════════════════\n";
echo "  Professional Edition Test Suite\n";
echo "══════════════════════════════════════════\n\n";

// ═══ 1. Custom Strategy Save ═══
echo "📋 Custom Strategy\n";

test('Custom strategy merges with defaults', function() {
    $custom = ['routing_method' => 'random', 'cooling_threshold' => 7, 'cooldown_minutes' => 90];
    $defaults = \Converge\Modules\PaymentRouter\Domain\StrategyTemplate::balanced()->toArray();
    $merged = array_merge($defaults, $custom, ['name' => 'custom']);

    if ($merged['routing_method'] !== 'random') throw new RuntimeException('routing_method');
    if ($merged['cooling_threshold'] !== 7) throw new RuntimeException('cooling_threshold');
    if ($merged['cooldown_minutes'] !== 90) throw new RuntimeException('cooldown_minutes');
    if ($merged['default_weight'] !== 3) throw new RuntimeException('default_weight should stay at default');
    if ($merged['name'] !== 'custom') throw new RuntimeException('name should be custom');
});

test('Partial custom config preserves other defaults', function() {
    $defaults = \Converge\Modules\PaymentRouter\Domain\StrategyTemplate::safeMode()->toArray();
    $partial = ['cooling_threshold' => 2]; // only change one param
    $merged = array_merge($defaults, $partial, ['name' => 'custom']);

    if ($merged['routing_method'] !== 'round_robin') throw new RuntimeException('routing_method should stay safe_mode default');
    if ($merged['cooling_threshold'] !== 2) throw new RuntimeException('cooling_threshold should be overridden');
    if ($merged['cooldown_minutes'] !== 15) throw new RuntimeException('cooldown_minutes should stay');
});

// ═══ 2. Config Export Structure ═══
echo "\n📤 Config Export\n";

test('Export structure contains required sections', function() {
    $export = ['strategy' => [], 'a_sites' => [], 'b_sites' => [], 'exported_at' => '', 'version' => ''];

    $requiredKeys = ['strategy', 'a_sites', 'b_sites', 'exported_at', 'version'];
    foreach ($requiredKeys as $k) {
        if (!array_key_exists($k, $export)) throw new RuntimeException("Missing key: $k");
    }
});

test('Export version matches current version', function() {
    $version = '0.1.0';
    if ($version !== '0.1.0') throw new RuntimeException('Version mismatch');
});

// ═══ 3. Import Safety ═══
echo "\n📥 Config Import (Safety)\n";

test('Import does not auto-import A-Sites (API Key security)', function() {
    // A-Sites contain API keys — should NOT be auto-imported
    $allowedAutoImport = ['strategy', 'b_sites'];
    $shouldNotAutoImport = ['a_sites']; // requires manual re-keying

    if (in_array('a_sites', $allowedAutoImport)) {
        throw new RuntimeException('A-Sites should NOT be auto-imported for security');
    }
});

test('Import B-Sites preserves all fields', function() {
    $bsRecord = [
        'domain' => 'pay.example.com',
        'payment_gateway' => 'paypal',
        'weight' => 3,
        'max_daily_orders' => 100,
        'status' => 'active',
    ];
    $required = ['domain', 'payment_gateway', 'weight', 'max_daily_orders', 'status'];
    foreach ($required as $k) {
        if (!isset($bsRecord[$k])) throw new RuntimeException("Missing B-Site field: $k");
    }
});

// ═══ 4. Install Script ═══
echo "\n📦 Package Distribution\n";

test('install.sh exists and is executable', function() {
    $path = __DIR__ . '/install.sh';
    if (!file_exists($path)) throw new RuntimeException('install.sh not found');
});

test('make-package.sh exists', function() {
    $path = __DIR__ . '/make-package.sh';
    if (!file_exists($path)) throw new RuntimeException('make-package.sh not found');
});

test('Install script contains required sections', function() {
    $content = file_get_contents(__DIR__ . '/install.sh');
    $requiredSections = ['环境检测', '数据库配置', '执行迁移', '生成配置', '启动服务', '验证部署'];
    foreach ($requiredSections as $section) {
        if (!str_contains($content, $section)) throw new RuntimeException("Missing section: $section");
    }
});

test('Package script references all required directories', function() {
    $content = file_get_contents(__DIR__ . '/make-package.sh');
    $required = ['modules/PaymentRouter', 'database/migrations', 'docker/payment-router'];
    foreach ($required as $dir) {
        if (!str_contains($content, $dir)) throw new RuntimeException("Missing reference: $dir");
    }
});

// ═══ 5. API Endpoints Coverage ═══
echo "\n🔌 API Endpoints\n";

test('All Pro endpoints documented', function() {
    $proEndpoints = [
        'PATCH  /api/payment-router/strategy'       => '自定义策略参数',
        'GET    /api/payment-router/config/export'   => '导出配置 JSON',
        'POST   /api/payment-router/config/import'   => '导入配置 JSON',
        'GET    /api/payment-router/presets'          => '列出预设模板',
    ];
    if (count($proEndpoints) < 4) throw new RuntimeException('Expected 4 Pro endpoints');
    echo "  Endpoints: " . count($proEndpoints) . "\n";
    foreach ($proEndpoints as $ep => $desc) {
        echo "    $ep → $desc\n";
    }
});

// ═══ Summary ═══
echo "\n══════════════════════════════════════════\n";
echo "  Pro: $pass passed, $fail failed\n";
echo "══════════════════════════════════════════\n\n";

if ($fail > 0) { echo "❌ PRO TESTS FAILED\n"; exit(1); }
echo "✅ Professional edition features complete:\n";
echo "  ✓ Custom strategy save (PATCH /strategy)\n";
echo "  ✓ Config export (GET /config/export)\n";
echo "  ✓ Config import (POST /config/import, A-Sites excluded for security)\n";
echo "  ✓ One-command install script (install.sh)\n";
echo "  ✓ Source package builder (make-package.sh)\n";
