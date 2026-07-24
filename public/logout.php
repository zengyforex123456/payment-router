<?php

declare(strict_types=1);

/**
 * Converge - Logout
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../kernel/src/Foundation/Database/db.php';
require_once APP_ROOT . '/app/bootstrap_web_paths.php';

use Converge\Security\Auth;

$db = db()->raw();
$auth = new Auth($db);
$auth->logout();
$db->close();

// Use BASE_URL for proper redirect in any directory
header('Location: ' . APP_BASE_URL . '/login-v2.php');
exit;

