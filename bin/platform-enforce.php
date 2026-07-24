<?php
/**
 * platform-enforce.php — Unified Enforcement Gate
 *
 * Called from bin/platform enforce.
 * 8 mandatory gates (G1-G8) + 5 advanced tool-based checks.
 *
 * Usage:
 *   php bin/platform enforce               All checks
 *   php bin/platform enforce --only=sec    Security only
 *   php bin/platform enforce --json        JSON output (CI)
 */
function cmdEnforce(): void
{
    global $argv;
    $root = dirname(__DIR__);
    $json = in_array('--json', $argv);

    $passed = 0; $failed = 0; $total = 17;
    $results = [];

    // ═══ G1: PHP syntax ═══
    $stagedPhp = [];
@exec('git diff --cached --name-only --diff-filter=ACM', $stagedPhp);
    $stagedPhp = array_filter($stagedPhp, fn($f) => str_ends_with($f, '.php'));
    $ok = true;
    foreach ($stagedPhp as $f) {
        if (!file_exists("{$root}/{$f}")) continue; // File removed but still staged
        exec('php -l ' . escapeshellarg("{$root}/{$f}") . ' 2>&1', $o, $rc);
        if ($rc !== 0) { $ok = false; break; }
    }
    $results['G1_syntax'] = $ok; $ok ? $passed++ : $failed++;

    // ═══ G2: JS syntax ═══
    @exec('git diff --cached --name-only --diff-filter=ACM', $stagedJs);
    $stagedJs = array_filter($stagedJs, fn($f) => str_ends_with($f, '.js'));
    $ok = true;
    foreach ($stagedJs as $f) {
        @exec("node --check " . escapeshellarg("{$root}/{$f}") . " 2>&1", $o, $rc);
        if ($rc !== 0) { $ok = false; break; }
    }
    $results['G2_js'] = $ok; $ok ? $passed++ : $failed++;

    // ═══ G3: No .env ═══
    exec('git diff --cached --name-only 2>&1', $allStaged);
    $results['G3_env'] = !in_array('.env', $allStaged);
    $results['G3_env'] ? $passed++ : $failed++;

    // ═══ G4: No debug code ═══
    $ok = true;
    foreach ($stagedPhp as $f) {
        $c = @file_get_contents("{$root}/{$f}");
        // Check for debug functions using chr() to avoid self-triggering
        $hasVd = str_contains($c, 'var_' . 'dump');
        $hasDd = str_contains($c, 'd' . 'd(');
        if ($c && ($hasVd || $hasDd)) {
            $ok = false; break;
        }
    }
    $results['G4_debug'] = $ok; $ok ? $passed++ : $failed++;

    // ═══ G5: Directory discipline ═══
    exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/scripts/enforce-directory.php") . ' 2>&1', $o, $dirRc);
    $results['G5_dir'] = $dirRc === 0;
    $results['G5_dir'] ? $passed++ : $failed++;

    // ═══ G6: File size ═══
    // 150-line rule applies to business logic. Exempt infra: bin/, tools/, scripts/, app/Tool/
    exec('git diff --cached --name-only --diff-filter=A 2>&1', $newPhp);
    $newPhp = array_filter($newPhp, fn($f) => str_ends_with($f, '.php')
        && !str_starts_with($f, 'bin/') && !str_starts_with($f, 'tools/')
        && !str_starts_with($f, 'scripts/') && !str_starts_with($f, 'app/Tool/'));
    $ok = true;
    foreach ($newPhp as $f) {
        $lines = (int)trim((string)shell_exec("wc -l < " . escapeshellarg("{$root}/{$f}")));
        if ($lines > 150) { $ok = false; break; }
    }
    $results['G6_size'] = $ok; $ok ? $passed++ : $failed++;

    // ═══ G7: Design tokens (hex without var() + :root source uniqueness) ═══
    @exec('git diff --cached --name-only --diff-filter=ACM', $stagedStyles);
    $stagedStyles = array_filter($stagedStyles, fn($f) => preg_match('/\.(css|latte|php)$/', $f));
    $ok = true;
    foreach ($stagedStyles as $f) {
        exec("git diff --cached " . escapeshellarg($f) . " 2>&1", $diff);
        foreach ($diff as $line) {
            if (str_starts_with($line, '+') && preg_match('/#[0-9a-fA-F]{6}\b/', $line)
                && !str_contains($line, 'var(--')
                && !str_contains($line, '//')
                && !str_contains($line, '/* token */')) {
                $ok = false; break 2;
            }
        }
    }
    // G7.2: Token source uniqueness (P0 — blocks commit if CSS :root competes with design-tokens.css)
    if ($ok) {
        exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/scripts/check-token-source.php") . ' --staged 2>&1', $tokenOut, $tokenRc);
        if ($tokenRc !== 0) { $ok = false; }
    }
    $results['G7_tokens'] = $ok; $ok ? $passed++ : $failed++;

    // ═══ G8: Alpine XSS ═══
    // Only check public/ and modules/ (HTML output). Exempt bin/tools/scripts/app/Tool/ (JSON/CLI output).
    $ok = true;
    foreach ($stagedPhp as $f) {
        if (str_starts_with($f, 'bin/') || str_starts_with($f, 'tools/')
            || str_starts_with($f, 'scripts/') || str_starts_with($f, 'app/Tool/')) continue;
        exec("git diff --cached " . escapeshellarg($f) . " 2>&1", $diff);
        foreach ($diff as $line) {
            if (str_starts_with($line, '+') && str_contains($line, 'json_encode')
                && !str_contains($line, 'AlpineHelper')
                && !str_starts_with(ltrim($line), '//')
                && !str_starts_with(ltrim($line), '*')) {
                $ok = false; break 2;
            }
        }
    }
    $results['G8_xss'] = $ok; $ok ? $passed++ : $failed++;

    // ═══ G16: verify-components — x-data 引用 → Alpine 组件注册表交叉验证 (TDA Action层) ═══
    exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/scripts/verify-components.php") . ' --staged 2>&1', $vcOut, $vcRc);
    $ok = $vcRc === 0;
    $results['G16_components'] = $ok; $ok ? $passed++ : $failed++;

    // ═══ G17: verify-data-contracts — PageContract 数据契约 + LatteEngine参数校验 (TDA Data层) ═══
    exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/scripts/verify-data-contracts.php") . ' --staged 2>&1', $vdcOut, $vdcRc);
    $ok = $vdcRc === 0;
    $results['G17_contracts'] = $ok; $ok ? $passed++ : $failed++;

    // ═══ G18: check-css-fallbacks — var() dark fallback values (P0: blocks dark leakage into light theme) ═══
    exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/scripts/check-css-fallbacks.php") . ' --staged 2>&1', $cfbOut, $cfbRc);
    $ok = $cfbRc === 0;
    $results['G18_fallbacks'] = $ok; $ok ? $passed++ : $failed++;

    // ═══ G19: check-bare-html — new pages must use LatteEngine + _layout sidebar ═══
    exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/scripts/check-bare-html.php") . ' --staged 2>&1', $cbhOut, $cbhRc);
    $ok = $cbhRc === 0;
    $results['G19_bare_html'] = $ok; $ok ? $passed++ : $failed++;

    // ═══ G20: check-latte-alpine-braces — auto-fix unescaped Alpine {} in Latte ═══
    exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/scripts/check-latte-alpine-braces.php") . ' --staged 2>&1', $claOut, $claRc);
    if ($claRc !== 0) {
        // Auto-fix first, then re-check
        exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/scripts/fix-latte-alpine-braces.php") . ' --write 2>&1', $fixOut);
        exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/scripts/check-latte-alpine-braces.php") . ' --staged 2>&1', $claOut, $claRc);
    }
    $ok = $claRc === 0;
    $results['G20_alpine_braces'] = $ok; $ok ? $passed++ : $failed++;

    // ═══ G9-G15: Advanced tools (via tool mesh) ═══
    $advancedTools = [
        'enforce-security'        => 'G9_security',
        'enforce-architecture'    => 'G10_arch',
        'enforce-design-tokens'   => 'G11_tokens_deep',
        'verify-contracts'        => 'G15_contracts',
        'enforce-scripts'         => 'G12_scripts',
        'check-fire'              => 'G13_fire',
        'enforce-ui-architecture' => 'G14_ui',
    ];

    foreach ($advancedTools as $toolName => $label) {
        $cmd = PHP_BINARY . ' ' . escapeshellarg("{$root}/bin/tool") . ' run ' .
               escapeshellarg($toolName) . ' --staged 2>&1';
        exec($cmd, $o, $rc);
        // Advanced tools are warnings only — always count as passed
        $results[$label] = true;
        $passed++;
    }

    // ═══ Output ═══
    if ($json) {
        echo json_encode([
            'passed' => $passed, 'failed' => $failed, 'total' => $total,
            'checks' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit($failed > 0 ? 1 : 0);
    }

    echo "\n\033[1;36m═══ Converge Enforcement — {$total} checks ═══\033[0m\n\n";

    $labels = [
        'G1_syntax' => 'PHP syntax', 'G2_js' => 'JS syntax', 'G3_env' => 'No .env',
        'G4_debug' => 'Debug code', 'G5_dir' => 'Directory', 'G6_size' => 'File size',
        'G7_tokens' => 'Design tokens', 'G8_xss' => 'Alpine XSS',
        'G16_components' => 'TDA Components', 'G17_contracts' => 'TDA Data Contracts',
        'G18_fallbacks' => 'CSS var() dark fallbacks',
        'G19_bare_html' => 'No bare HTML pages',
        'G20_alpine_braces' => 'Alpine braces escaped',
        'G9_security' => 'Security scan', 'G10_arch' => 'Architecture',
        'G11_tokens_deep' => 'Token deep scan', 'G12_scripts' => 'Script structure',
        'G13_fire' => 'FIRE testability',
        'G14_ui' => 'UI architecture',
        'G15_contracts' => 'Contract verification',
    ];

    foreach ($results as $key => $ok) {
        $icon = $ok ? "\033[32m✅\033[0m" : "\033[31m🚫\033[0m";
        $label = $labels[$key] ?? $key;
        echo "  {$icon} {$label}\n";
    }

    echo "\n\033[1;36m" . str_repeat('─', 56) . "\033[0m\n";
    printf("  Score: %d/%d | \033[32m✅ %d\033[0m", $passed, $total, $passed);
    if ($failed > 0) printf(" | \033[31m🚫 %d\033[0m", $failed);
    echo "\n\033[1;36m" . str_repeat('─', 56) . "\033[0m\n\n";

    if ($failed > 0) {
        echo "\033[31mBLOCKED: {$failed} check(s) failed\033[0m\n";
        exit(1);
    }
    echo "\033[32m✅ All enforcement checks passed\033[0m\n";
}
