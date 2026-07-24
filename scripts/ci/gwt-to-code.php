#!/usr/bin/env php
<?php
/**
 * gwt-to-code.php — GWT → 代码骨架自动生成（可实例化）
 *
 * 读取 PRD 需求表，对每条有 Given-When-Then 的需求：
 *   1. 生成 Playwright E2E 测试骨架 → tests/E2E/{req-id}.spec.js
 *   2. 推断 API 路由 → public/api-{slug}.php 骨架
 *
 * 生成的测试是 RED（必定失败），开发者填空让它 GREEN。
 *
 * 用法: php ci/gwt-to-code.php [--dry-run] [--req=R1]
 *   --dry-run   仅打印，不写文件
 *   --req=R1    仅处理指定需求
 */

$prdPath = APP_ROOT . '/.claude/prd.md';
$specDir = APP_ROOT . '/tests/E2E';
$apiDir  = APP_ROOT . '/public';

if (!file_exists($prdPath)) { die("PRD not found\n"); }

$dryRun  = in_array('--dry-run',  $argv ?? []);
$single  = null;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--req=')) { $single = substr($a, 6); }
}

$prd  = file_get_contents($prdPath);
$lines = explode("\n", $prd);
$generated = ['specs' => [], 'apis' => []];

foreach ($lines as $i => $line) {
    // 匹配: | R1 | 描述 | GWT内容 | 验收 | 不确定性 | 类型 | 文件 |
    if (!preg_match('/^\|\s*(R\d+|N\d+|M\d+|C\d+|I\d+|T\d+|O\d+|S\d+|UT\d+)\s*\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|/', $line, $m)) {
        continue;
    }
    $id = $m[1]; $desc = trim($m[2]); $gwt = trim($m[3]);

    if ($single && $id !== $single) continue;
    if ($gwt === '—') continue;           // 存量跳过
    if (!preg_match('/Given.+When.+Then/i', $gwt)) continue;  // 无 GWT 跳过

    echo "━━━ {$id}: {$desc} ━━━\n";

    // ═══ 1. 生成 Playwright E2E 测试骨架 ═══
    $specFile = $specDir . '/' . strtolower($id) . '.spec.js';
    $specCode = buildSpec($id, $desc, $gwt);
    if (!$dryRun) {
        @mkdir($specDir, 0777, true);
        file_put_contents($specFile, $specCode);
    }
    $generated['specs'][] = $specFile;
    echo "  📝 E2E: " . basename($specFile) . "\n";

    // ═══ 2. 推断 API 路由并生成骨架 ═══
    $apiInfo = inferApi($id, $desc, $gwt);
    if ($apiInfo) {
        $apiFile = $apiDir . '/' . $apiInfo['file'];
        $apiCode = buildApiStub($id, $desc, $gwt, $apiInfo);
        if (!$dryRun) {
            file_put_contents($apiFile, $apiCode);
        }
        $generated['apis'][] = $apiFile;
        echo "  🔗 API: {$apiInfo['method']} {$apiInfo['route']} → " . basename($apiFile) . "\n";
    }

    if (!$dryRun) echo "\n";
}

// ═══ 汇总 ═══
echo "══════════════════════════════════\n";
echo "生成完毕: " . count($generated['specs']) . " E2E spec + " . count($generated['apis']) . " API 骨架\n";

if (count($generated['specs'])) {
    echo "\n运行测试:\n";
    echo "  E2E_URL=http://localhost:8080 npx playwright test tests/E2E/ --grep \"" . $id . "\"\n";
    echo "\n⚠️  生成的测试全部 RED（必定失败）。现在去填空让它们 GREEN。\n";
}

// ═══════════════════════════════════════
// 生成函数
// ═══════════════════════════════════════

/**
 * 将 GWT 转换为 Playwright spec 文件
 */
function buildSpec(string $id, string $desc, string $gwt): string
{
    $cleanDesc = addslashes($desc);
    $cleanGwt  = addslashes($gwt);

    // 提取 Given / When / Then 条目
    preg_match_all('/Given\s+(.+?)(?=\s*(?:When|Then|And|$))/is', $gwt, $givens);
    preg_match_all('/When\s+(.+?)(?=\s*(?:Then|And|$))/is', $gwt, $whens);
    preg_match_all('/Then\s+(.+?)(?=\s*(?:And|$))/is', $gwt, $thens);
    preg_match_all('/And\s+(.+?)(?=\s*(?:Given|When|Then|And|$))/is', $gwt, $ands);

    $givenSteps = array_map('trim', $givens[1] ?? []);
    $whenSteps  = array_map('trim', $whens[1]  ?? []);
    $thenSteps  = array_map('trim', $thens[1]  ?? []);
    $andSteps   = array_map('trim', $ands[1]   ?? []);

    // 生成 Given 步骤注释
    $givenComments = '';
    foreach ($givenSteps as $g) {
        $givenComments .= " *    Given {$g}\n";
    }

    // 生成 When→action（简单推断）
    $actions = buildActions($whenSteps, $givenSteps);

    // 生成 Then→assertion
    $assertions = buildAssertions($thenSteps, $andSteps);

    // 安全取值（避免 ?? 在 heredoc 中语法错误）
    $ws0 = $whenSteps[0] ?? '';
    $ws1 = isset($whenSteps[1]) ? ' ' . $whenSteps[1] : '';
    $ts0 = $thenSteps[0] ?? '';
    $ts1 = isset($thenSteps[1]) ? ' ' . $thenSteps[1] : '';

    return <<<JS
/**
 * {$id}.spec.js — {$cleanDesc}
 *
 * GWT:
{$givenComments} *    When {$ws0}{$ws1}
 *    Then {$ts0}{$ts1}
 *
 * 生成: ci/gwt-to-code.php | 状态: 🟡 RED（待填空）
 */
const { test, expect } = require('@playwright/test');
const { BASE, login } = require('./helpers/auth');

test.describe('{$id}: {$cleanDesc}', () => {
  test('GWT 快乐路径', async ({ page }) => {
{$actions}
{$assertions}
  });

  test('GWT 异常路径 — 空输入/非法参数', async ({ page }) => {
    // TODO: 根据 Given-When-Then 补充异常输入
    await login(page);
    // 异常场景: 参数缺失 / 越界 / 重复 / 权限不足
    // expect(...)
    test.skip();  // ← 填写后删除这行
  });
});

JS;
}

/**
 * 从 When 步骤推断 Playwright action
 */
function buildActions(array $whenSteps, array $givenSteps): string
{
    $code = '';
    $needsLogin = false;

    // 检查 Given 是否需要登录
    foreach ($givenSteps as $g) {
        if (preg_match('/登录|登陆|login|auth/i', $g)) $needsLogin = true;
    }
    if ($needsLogin) {
        $code .= "    await login(page);\n";
    } else {
        // 大多数功能需要登录
        $code .= "    await login(page);\n";
    }

    foreach ($whenSteps as $step) {
        $code .= "\n    // When {$step}\n";

        if (preg_match('/点击|click|press/i', $step)) {
            $sel = inferSelector($step);
            $code .= "    await page.click('{$sel}');\n";
        } elseif (preg_match('/输入|填写|fill|type|enter/i', $step)) {
            $sel = inferSelector($step);
            $code .= "    await page.fill('{$sel}', 'TODO: 测试值');\n";
        } elseif (preg_match('/打开|访问|goto|navigate/i', $step)) {
            $slug = inferSlug($step);
            $code .= "    await page.goto(BASE + '{$slug}');\n";
        } elseif (preg_match('/提交|submit|保存|save/i', $step)) {
            $code .= "    await page.click('button[type=\"submit\"]');\n";
        } elseif (preg_match('/选择|select|choose/i', $step)) {
            $sel = inferSelector($step);
            $code .= "    await page.selectOption('{$sel}', 'TODO');\n";
        } else {
            $code .= "    // TODO: 实现 When{$step}\n";
        }
    }

    return $code;
}

/**
 * 从 Then 步骤推断 Playwright assertion
 */
function buildAssertions(array $thenSteps, array $andSteps): string
{
    $allSteps = array_merge($thenSteps, $andSteps);
    $code = '';

    foreach ($allSteps as $step) {
        $code .= "\n    // Then {$step}\n";

        if (preg_match('/可见|visible|出现|展示|display|render/i', $step)) {
            $sel = inferSelector($step);
            $code .= "    await expect(page.locator('{$sel}').first()).toBeVisible();\n";
        } elseif (preg_match('/包含|contain|include/i', $step)) {
            $sel = inferSelector($step);
            $code .= "    await expect(page.locator('{$sel}').first()).toContainText('TODO');\n";
        } elseif (preg_match('/跳转|redirect|导航|navigate/i', $step)) {
            $code .= "    await expect(page).toHaveURL(/TODO/);\n";
        } elseif (preg_match('/隐藏|hidden|不可见|invisible/i', $step)) {
            $sel = inferSelector($step);
            $code .= "    await expect(page.locator('{$sel}')).toBeHidden();\n";
        } elseif (preg_match('/不小于|不少于|≥|>=|gte\s+(\d+)/i', $step, $n)) {
            $sel = inferSelector($step);
            $code .= "    expect(await page.locator('{$sel}').count()).toBeGreaterThanOrEqual({$n[1]});\n";
        } elseif (preg_match('/等于|==|===|exactly\s+(\d+)/i', $step, $n)) {
            $sel = inferSelector($step);
            $code .= "    expect(await page.locator('{$sel}').count()).toBe({$n[1]});\n";
        } elseif (preg_match('/提示|toast|message|alert|通知/i', $step)) {
            $code .= "    await expect(page.locator('.toast,.alert,.message,.notice').first()).toBeVisible();\n";
        } elseif (preg_match('/列表|table|list|表格/i', $step)) {
            $code .= "    await expect(page.locator('table tbody tr, .list-item').first()).toBeVisible();\n";
        } elseif (preg_match('/(\d+)px|宽度|width/i', $step)) {
            $sel = inferSelector($step);
            $code .= "    await expect(page.locator('{$sel}')).toHaveCSS('width', 'TODO');\n";
        } else {
            $code .= "    // TODO: 实现 Then{$step}\n";
        }
    }

    return $code;
}

/**
 * 推断路由: 需求是否对应 API 端点
 */
function inferApi(string $id, string $desc, string $gwt): ?array
{
    // 需求描述有关键词→推断为 API
    if (preg_match('/接口|api|endpoint|端点|postback|webhook|回调/i', $desc)) {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $desc));
        $slug = trim($slug, '-');
        return ['file' => "api-{$slug}.php", 'route' => "/api-{$slug}.php", 'method' => 'POST'];
    }
    if (preg_match('/查询|query|统计|报表|stats|dashboard|report/i', $desc)) {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $desc));
        $slug = trim($slug, '-');
        return ['file' => "api-{$slug}.php", 'route' => "/api-{$slug}.php", 'method' => 'GET'];
    }
    // CRUD 操作
    if (preg_match('/创建|新建|create|add|上传|upload/i', $desc)) {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $desc));
        $slug = trim($slug, '-');
        return ['file' => "api-{$slug}.php", 'route' => "/api-{$slug}.php", 'method' => 'POST'];
    }
    return null;
}

/**
 * 生成 API 端点骨架
 */
function buildApiStub(string $id, string $desc, string $gwt, array $info): string
{
    $cleanDesc = addslashes($desc);
    $method = $info['method'];

    $inputParse = '';
    if ($method === 'POST') {
        $inputParse = '$input = json_decode(file_get_contents(\'php://input\'), true) ?: [];' . "\n";
    } else {
        $inputParse = '$input = $_GET;' . "\n";
    }

    return <<<PHP
<?php
/**
 * {$info['route']} — {$cleanDesc}
 *
 * 来源: PRD {$id}
 * GWT: {$gwt}
 * 生成: ci/gwt-to-code.php | 状态: 🟡 骨架（待填空）
 *
 * @method {$method}
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: {$method}, OPTIONS');

if (\$_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if (\$_SERVER['REQUEST_METHOD'] !== '{$method}') { http_response_code(405); exit; }

{$inputParse}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

// ═══ TODO: 实现业务逻辑 ═══
// Given: [从 GWT 提取前置条件]
// When:  [从 GWT 提取触发条件]
// Then:  [从 GWT 提取预期结果]

try {
    \$db = new \\mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    \$db->set_charset('utf8mb4');

    // TODO: 实现核心逻辑
    // \$result = ...

    echo json_encode([
        'ok'   => true,
        'data' => [
            'req_id' => '{$id}',
            'status' => 'stub',  // ← 改为 'implemented' 后删除这行
            'message' => '骨架已生成，请实现业务逻辑',
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (\\Throwable \$e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => \$e->getMessage(),
    ]);
}

PHP;
}

// ═══════════════════════════════════════
// 简单推断函数
// ═══════════════════════════════════════

function inferSelector(string $step): string
{
    // 简单推断: 从中文描述推测 CSS 选择器
    if (preg_match('/按钮|button|btn|点击|click/i', $step)) {
        return 'button, .btn, [role="button"]';
    }
    if (preg_match('/输入|input|表单|form|填写/i', $step)) {
        return 'input, textarea, select';
    }
    if (preg_match('/表格|table|列表|list/i', $step)) {
        return 'table, .list, [role="list"]';
    }
    if (preg_match('/面板|panel|sidebar|侧边/i', $step)) {
        return '.panel, .sidebar, [role="navigation"]';
    }
    if (preg_match('/标题|title|h1|heading/i', $step)) {
        return 'h1, h2, [role="heading"]';
    }
    if (preg_match('/链接|link|href/i', $step)) {
        return 'a[href]';
    }
    if (preg_match('/图标|icon/i', $step)) {
        return '.icon, [data-icon], svg';
    }
    if (preg_match('/搜索|search/i', $step)) {
        return 'input[type="search"], [role="searchbox"]';
    }
    // 默认: 从步骤中提取最后一个名词作为 CSS class
    preg_match('/([\w-]+)\s*$/', $step, $m);
    $last = $m[1] ?? 'element';
    return '.' . strtolower($last);
}

function inferSlug(string $step): string
{
    // 从步骤中提取 URL 关键词
    if (preg_match('/landing|着陆页|落地页|构建器/i', $step)) return '/landing.php';
    if (preg_match('/dashboard|仪表盘|工作台/i', $step)) return '/index.php';
    if (preg_match('/campaign|活动|推广/i', $step)) return '/index.php?page=campaigns';
    if (preg_match('/offer|报价/i', $step)) return '/index.php?page=offers';
    if (preg_match('/funnel|漏斗/i', $step)) return '/index.php?page=funnels';
    if (preg_match('/login|登录/i', $step)) return '/login-v2.php';
    if (preg_match('/register|注册/i', $step)) return '/register.php';
    return '/index.php';
}
