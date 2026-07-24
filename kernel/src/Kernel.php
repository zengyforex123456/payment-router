<?php
declare(strict_types=1);
namespace ZhiceOS;

/**
 * Kernel — 智策 OS 内核启动器
 *
 * 每个应用在 public/index.php 中调用 Kernel::boot() 即可获得：
 *   - 自动加载
 *   - 数据库连接 (ConnectionManager)
 *   - 模块扫描与加载 (ModuleLoader)
 *   - 错误处理注册
 */
final class Kernel
{
    private static bool $booted = false;

    /** 启动内核。幂等——多次调用只执行一次。 */
    public static function boot(string $appRoot): void
    {
        if (self::$booted) return;

        // 1. 自动加载
        $vendorAutoload = $appRoot . '/vendor/autoload.php';
        if (!file_exists($vendorAutoload)) {
            $vendorAutoload = dirname(__DIR__, 3) . '/vendor/autoload.php'; // monorepo fallback
        }
        require_once $vendorAutoload;

        // 2. 环境配置
        $envFile = $appRoot . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (!str_contains($line, '=')) continue;
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }

        // 3. 时区 & 错误
        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');
        if (($_ENV['APP_ENV'] ?? 'dev') === 'dev') {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
            ini_set('display_errors', '0');
        }

        // 4. 租户解析 (多租户中间件 — 商业价值核心)
        \Converge\Foundation\System\TenantScope::resolve();

        // 5. 数据库连接
        $GLOBALS['db'] = \Converge\Foundation\System\ConnectionManager::get();

        // 5. 模块加载
        $loader = new \Converge\Core\Module\ModuleLoader();
        $loader->addPath($appRoot . '/modules');
        // 扫描已安装的 converge 集群包
        foreach (glob($appRoot . '/vendor/converge/*/src', GLOB_ONLYDIR) as $pkgSrc) {
            $loader->addPath($pkgSrc);
        }
        $loader->bootstrap();

        self::$booted = true;
    }

    /** 内核版本 */
    public static function version(): string
    {
        return '3.2.0';
    }
}
