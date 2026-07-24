<?php
/**
 * docker/verify-deps.php — Build-time dependency verification gate
 *
 * Runs inside Docker build after composer install.
 * Exit 0 = pass, non-zero = build fails.
 */

$errors = [];

// 1. Composer autoloader
$autoloader = '/var/www/converge/vendor/autoload.php';
if (!file_exists($autoloader)) {
    $errors[] = "Missing: vendor/autoload.php — composer install failed";
} else {
    require_once $autoloader;
}

// 2. PHP extensions
$required = ['mysqli', 'pdo_mysql', 'mbstring', 'zip', 'redis', 'bcmath', 'curl'];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        $errors[] = "Missing PHP extension: {$ext}";
    }
}

// 3. Key classes autoloadable (spot-check)
$classes = [
    'Converge\\Core\\Hook\\Hooks',
    'Converge\\Core\\Module\\ModuleLoader',
    'Converge\\UI\\Engine\\LatteEngine',
];
foreach ($classes as $cls) {
    if (!class_exists($cls)) {
        $errors[] = "Class not autoloadable: {$cls}";
    }
}

// 4. Required directories
$dirs = ['/var/www/converge/public', '/var/www/converge/templates', '/var/www/converge/storage'];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        $errors[] = "Missing directory: {$dir}";
    }
}

if (!empty($errors)) {
    echo "❌ Dependency verification FAILED:\n";
    foreach ($errors as $e) echo "  - {$e}\n";
    exit(1);
}

echo "✅ Dependencies OK — " . count($required) . " extensions, " . count($classes) . " classes\n";
