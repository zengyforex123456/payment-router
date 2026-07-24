<?php
/** admin-panel.php — Main dashboard (starter) */
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../kernel/src/Foundation/Database/db.php';

use Converge\Security\Auth;
use Converge\I18n\Locale;
use Converge\UI\Engine\LatteEngine;

$db = db()->raw();
$auth = new Auth($db);
$auth->requireAuth();
Locale::init();
$lang = Locale::lang();

LatteEngine::display('pages/dashboard', [
    'headExtra' => '',
    'lang' => $lang,
    'zh' => $lang === 'zh',
    'title' => 'Dashboard',
]);
