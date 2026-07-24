#!/usr/bin/env php
<?php
/**
 * check-assets.php — 静态资源引用完整性检查
 *
 * 扫描所有 PHP/HTML/Latte 中的 <script src>, <link href>, <img src> 引用，
 * 验证目标文件在仓库中存在。缺失 → exit(1) → 阻断提交。
 *
 * 用法: php ci/check-assets.php
 */

declare(strict_types=1);

$projectRoot = realpath(__DIR__ . '/..');
$viewsDir    = $projectRoot . '/views';
$publicDir   = $projectRoot . '/public';
$templatesDir = $projectRoot . '/templates';

// 收集所有实际存在的静态文件
function collectAssets(string $dir): array
{
    $map = [];
    if (!is_dir($dir)) {
        return $map;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        if ($f->isFile()) {
            $rel = str_replace('\\', '/', $iter->getSubPathname());
            $map[$rel] = $f->getPathname();
        }
    }
    return $map;
}

$existing = collectAssets($publicDir);

// 扫描文件中的静态资源引用
function scanFile(string $file, array $existing, string $projectRoot): array
{
    $content = file_get_contents($file);
    $missing = [];
    $lines = explode("\n", $content);
    $rel = str_replace($projectRoot . '/', '', $file);

    foreach ($lines as $i => $line) {
        $lineNum = $i + 1;

        // 提取 src="..." href="..." 中的路径
        $attrs = extractUrls($line);
        foreach ($attrs as $url) {
            // 跳过外部 URL
            if (preg_match('#^(https?:|//)#i', $url)) {
                continue;
            }

            // 去掉 PHP 变量前缀
            $url = preg_replace('#<\?=\s*\$[a-zA-Z_]+\s*\?>#', '', $url);
            $url = preg_replace('#\{\$[a-zA-Z_]+\}#', '', $url);
            $url = preg_replace("/'\\s*\\.\\s*\\\$[a-zA-Z_]+\\s*\\.\\s*'/", '', $url);
            $url = trim($url, " \t\n\r\0\x0B;\"'");

            // 规范化路径
            $localPath = parse_url($url, PHP_URL_PATH);
            if (!$localPath || $localPath === $url) {
                $localPath = ltrim($url, '/');
            }

            $localPath = ltrim(preg_replace('/\?.*$/', '', $localPath), '/');

            if (empty($localPath) || str_starts_with($localPath, '$')) {
                continue;
            }

            if (!isset($existing[$localPath])) {
                $missing[] = [
                    'file' => $rel,
                    'line' => $lineNum,
                    'url'  => $url,
                    'lookup' => $localPath,
                ];
            }
        }
    }
    return $missing;
}

// 提取 HTML 属性中的 URL
function extractUrls(string $line): array
{
    $urls = [];

    // <script src="...">
    if (preg_match_all('#<script\s[^>]*src\s*=\s*"([^"]+)"#i', $line, $m)) {
        $urls = array_merge($urls, $m[1]);
    }
    // <link href="...">
    if (preg_match_all('#<link\s[^>]*href\s*=\s*"([^"]+)"#i', $line, $m)) {
        $urls = array_merge($urls, $m[1]);
    }
    // <img src="...">
    if (preg_match_all('#<img\s[^>]*src\s*=\s*"([^"]+)"#i', $line, $m)) {
        $urls = array_merge($urls, $m[1]);
    }

    return array_filter($urls, function ($u) {
        return !empty($u) && preg_match('#\.(?:js|css|png|svg|jpg|gif|ico|woff2?|ttf|eot)#i', $u);
    });
}

// 收集所有需要检查的文件
$scanFiles = [];
foreach (['views', 'templates', 'public'] as $dir) {
    $fullDir = $projectRoot . '/' . $dir;
    if (!is_dir($fullDir)) {
        continue;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        if ($f->isFile() && in_array($f->getExtension(), ['php', 'latte', 'html'], true)) {
            $scanFiles[] = $f->getPathname();
        }
    }
}

// 扫描
$allMissing = [];
foreach ($scanFiles as $file) {
    $missing = scanFile($file, $existing, $projectRoot);
    $allMissing = array_merge($allMissing, $missing);
}

// 输出
if (empty($allMissing)) {
    echo "✅ 静态资源完整性: 全部通过\n";
    exit(0);
}

echo "\n🚫 静态资源缺失: " . count($allMissing) . " 处\n\n";
$grouped = [];
foreach ($allMissing as $m) {
    $grouped[$m['lookup']][] = $m['file'] . ':' . $m['line'];
}
foreach ($grouped as $path => $refs) {
    echo "  ❌ 缺失: {$path}\n";
    echo "     引用自: " . implode(', ', array_slice($refs, 0, 3));
    if (count($refs) > 3) {
        echo " ... +" . (count($refs) - 3);
    }
    echo "\n";
}

echo "\n原因: 文件未 git add / 未提交 / 路径错误\n";
echo "修复: git add <缺失文件> && git commit -m 'fix: add missing assets'\n";
exit(1);
