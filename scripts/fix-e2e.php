<?php
$f = __DIR__ . '/test-payment-router-e2e.php';
$c = file_get_contents($f);

// 1. Add require for stub
$c = str_replace(
    "// Load all module files",
    "require_once __DIR__ . '/../docker/payment-router/stubs/DatabaseInterface.php';\n\n// Load all module files",
    $c
);

// 2. Make $db implement DatabaseInterface
$c = str_replace(
    '$db = new class {',
    '$db = new class implements \Converge\Contracts\DatabaseInterface {',
    $c
);

// 3. Add missing methods to $db class (after prepare() method, before closing };)
$dbMethods = '
    public function query(string $sql): mixed { return null; }
    public function escape(string $v): string { return $v; }
    public function lastInsertId(): int { return 1; }
    public function affectedRows(): int { return 0; }
    public function raw(): mixed { return null; }';

// Find the first }; after $db = new class
$dbPos = strpos($c, '$db = new class');
$closingPos = strpos($c, '};', $dbPos);
$closingPos = strpos($c, "\n", $closingPos) + 1;
$c = substr($c, 0, $closingPos - 1) . $dbMethods . "\n" . substr($c, $closingPos - 1);

// 4. Update HandlePaymentWebhookUseCase constructor call
$c = str_replace(
    'new HandlePaymentWebhookUseCase($mappingRepo, $bRepo, 3)',
    'new HandlePaymentWebhookUseCase($mappingRepo, $bRepo, $db, 3)',
    $c
);

file_put_contents($f, $c);
echo "Fixed: $f\n";
