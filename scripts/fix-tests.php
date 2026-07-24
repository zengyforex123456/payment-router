<?php
// Fix mock repositories in test files to return entities from save()
$files = ['test-payment-router.php', 'test-payment-router-e2e.php', 'test-payment-router-saas.php'];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    $c = file_get_contents($path);
    $orig = $c;
    // Fix return types
    $c = str_replace('public function save(ASite $site): void', 'public function save(ASite $site): \Converge\Modules\PaymentRouter\Domain\ASite', $c);
    $c = str_replace('public function save(BSite $site): void', 'public function save(BSite $site): \Converge\Modules\PaymentRouter\Domain\BSite', $c);
    $c = str_replace('public function save(OrderMapping $mapping): void', 'public function save(OrderMapping $mapping): \Converge\Modules\PaymentRouter\Domain\OrderMapping', $c);
    // Fix bare return; → return $site|$mapping;
    $c = str_replace('$site; return;', '$site; return $site;', $c);
    $c = str_replace('$mapping; return;', '$mapping; return $mapping;', $c);
    // Add return at end of save methods that add to array but don't return
    $c = preg_replace('/\$this->sites\[\] = \$site;(\s*)}/', '$this->sites[] = $site; return $site;$1}', $c);
    $c = preg_replace('/\$this->mappings\[\] = new OrderMapping[^;]+;(\s*)}/', '$0 return $this->mappings[count($this->mappings)-1];$1}', $c);
    if ($c !== $orig) { file_put_contents($path, $c); echo "Fixed: $f\n"; } else { echo "No changes: $f\n"; }
}
echo "Done\n";
