<?php
/**
 * Cloak — Unit Tests
 */
declare(strict_types=1);
$base = __DIR__ . '/../modules/PaymentRouter/Cloak';
require_once "$base/Domain/CloakVisitor.php";
require_once "$base/Domain/CloakRule.php";
require_once "$base/Domain/CloakDecision.php";
require_once "$base/Application/IpIntelService.php";
require_once "$base/Infrastructure/BrowserFingerprint.php";
require_once "$base/Application/EvaluateCloakUseCase.php";

use Converge\Modules\PaymentRouter\Cloak\Domain\{CloakVisitor, CloakRule, CloakDecision};
use Converge\Modules\PaymentRouter\Cloak\Application\{EvaluateCloakUseCase, BuiltinIpIntel};

$p=0;$f=0;
function t(string $n, callable $fn): void { global $p,$f; try{$fn();echo"  ✅ $n\n";$p++;}catch(Throwable $e){echo"  ❌ $n — {$e->getMessage()}\n";$f++;} }

echo "══════════════════════════════════════════\n";
echo "  Cloak Engine Tests\n";
echo "══════════════════════════════════════════\n\n";

echo "🕵️ CloakRule Matching\n";
t('IP equals', function() {
    $r = new CloakRule(1, 'ip', 'equals', '192.168.1.1');
    $v = new CloakVisitor('192.168.1.1');
    if (!$r->matches($v)) throw new RuntimeException('Should match');
});
t('IP not equals', function() {
    $r = new CloakRule(1, 'ip', 'equals', '192.168.1.1');
    $v = new CloakVisitor('10.0.0.1');
    if ($r->matches($v)) throw new RuntimeException('Should not match');
});
t('User-Agent contains', function() {
    $r = new CloakRule(2, 'user_agent', 'contains', 'Googlebot');
    $v = new CloakVisitor('1.2.3.4', 'Mozilla/5.0 Googlebot/2.1');
    if (!$r->matches($v)) throw new RuntimeException('Should match');
});
t('Country equals case insensitive', function() {
    $r = new CloakRule(3, 'country', 'equals', 'US');
    $v = new CloakVisitor('1.2.3.4', '', '', '', 'us');
    if (!$r->matches($v)) throw new RuntimeException('Should match');
});
t('CIDR match', function() {
    $r = new CloakRule(4, 'ip', 'in_cidr', '69.171.224.0/19');
    $v = new CloakVisitor('69.171.230.5');
    if (!$r->matches($v)) throw new RuntimeException('Should match 69.171.224.0/19');
});
t('CIDR no match', function() {
    $r = new CloakRule(4, 'ip', 'in_cidr', '69.171.224.0/19');
    $v = new CloakVisitor('1.2.3.4');
    if ($r->matches($v)) throw new RuntimeException('Should not match');
});
t('Regex match', function() {
    $r = new CloakRule(5, 'user_agent', 'regex', '/bot|spider|crawl/i');
    $v = new CloakVisitor('1.2.3.4', 'TestBot/1.0');
    if (!$r->matches($v)) throw new RuntimeException('Should match regex');
});
t('is_empty true', function() {
    $r = new CloakRule(6, 'user_agent', 'is_empty', '');
    $v = new CloakVisitor('1.2.3.4', '');
    if (!$r->matches($v)) throw new RuntimeException('Should match empty');
});
t('not_empty false', function() {
    $r = new CloakRule(7, 'referrer', 'not_empty', '');
    $v = new CloakVisitor('1.2.3.4');
    if ($r->matches($v)) throw new RuntimeException('Should not match');
});

echo "\n🔍 Built-in Crawler Detection\n";
$engine = new EvaluateCloakUseCase([], new BuiltinIpIntel());

t('Facebook crawler → safe', function() use ($engine) {
    $v = new CloakVisitor('69.171.230.5', 'facebookexternalhit/1.1');
    $r = $engine->execute($v, 'https://safe.com', 'https://real.com');
    if ($r['action'] !== 'safe') throw new RuntimeException('Expected safe, got '.$r['action']);
});
t('Googlebot → safe', function() use ($engine) {
    $v = new CloakVisitor('66.249.70.1', 'Googlebot/2.1');
    $r = $engine->execute($v, 'https://safe.com', 'https://real.com');
    if ($r['action'] !== 'safe') throw new RuntimeException('Expected safe');
});
t('TikTok crawler → safe', function() use ($engine) {
    $v = new CloakVisitor('1.2.3.4', 'Bytespider/1.0');
    $r = $engine->execute($v, 'https://safe.com', 'https://real.com');
    if ($r['action'] !== 'safe') throw new RuntimeException('Expected safe');
});
t('Datacenter IP (AWS) → safe', function() use ($engine) {
    $v = new CloakVisitor('3.5.10.20', 'Mozilla/5.0');
    $r = $engine->execute($v, 'https://safe.com', 'https://real.com');
    if ($r['action'] !== 'safe') throw new RuntimeException('Expected safe for AWS IP');
});
t('Real user with browser signals → real (smart default)', function() use ($engine) {
    $v = new CloakVisitor('203.0.113.5', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'en-US,en', 'https://facebook.com/ads');
    $r = $engine->execute($v, 'https://safe.com', 'https://real.com');
    if ($r['action'] !== 'real') throw new RuntimeException('Real user signals should default to real, got '.$r['action']);
});
t('Suspicious user no signals → safe', function() use ($engine) {
    $v = new CloakVisitor('1.2.3.4', '', '', '');
    $r = $engine->execute($v, 'https://safe.com', 'https://real.com');
    if ($r['action'] !== 'safe') throw new RuntimeException('No signals should default to safe');
});
t('Real user with country rule → real', function() {
    $rule = new CloakRule(1, 'country', 'not_empty', '', 'real', 90);
    $engine = new EvaluateCloakUseCase([$rule], new BuiltinIpIntel());
    $v = new CloakVisitor('203.0.113.5', 'Mozilla/5.0', 'en-US', '', 'US');
    $r = $engine->execute($v, 'https://safe.com', 'https://real.com');
    if ($r['action'] !== 'real') throw new RuntimeException('Country rule should route to real');
});

echo "\n📋 Custom Rules\n";
t('Custom rule: block CN users', function() {
    $rule = new CloakRule(1, 'country', 'equals', 'CN', 'block', 50);
    $engine = new EvaluateCloakUseCase([$rule], new BuiltinIpIntel());
    $v = new CloakVisitor('1.2.3.4', 'Mozilla/5.0', '', '', 'CN');
    $r = $engine->execute($v, 'https://safe.com', 'https://real.com');
    if ($r['action'] !== 'block') throw new RuntimeException('Expected block for CN');
});
t('Custom rule: priority ordering (builtin takes precedence)', function() {
    // Builtin empty-UA detection ALWAYS runs first (security-first design)
    $r1 = new CloakRule(1, 'country', 'equals', 'US', 'real', 10);
    $engine = new EvaluateCloakUseCase([$r1], new BuiltinIpIntel());
    $v = new CloakVisitor('1.2.3.4', '', '', '', 'US');
    $r = $engine->execute($v, 'https://safe.com', 'https://real.com');
    // Builtin empty UA check triggers first → safe (correct security behavior)
    if ($r['action'] !== 'safe') throw new RuntimeException('Builtin crawler detection should take precedence');
});
t('Custom rule priority when no builtin match', function() {
    $r1 = new CloakRule(1, 'country', 'equals', 'US', 'real', 10);
    $r2 = new CloakRule(2, 'country', 'equals', 'US', 'safe', 20);
    $engine = new EvaluateCloakUseCase([$r2, $r1], new BuiltinIpIntel());
    $v = new CloakVisitor('1.2.3.4', 'Mozilla/5.0', 'en', '', 'US');
    $r = $engine->execute($v, 'https://safe.com', 'https://real.com');
    if ($r['action'] !== 'real') throw new RuntimeException('Priority 10 should beat priority 20');
});

echo "\n📡 IP Intel\n";
$intel = new BuiltinIpIntel();
t('AWS IP detected as datacenter', function() use ($intel) {
    $v = $intel->enrich(new CloakVisitor('3.5.10.20'));
    if (!$v->isDatacenter) throw new RuntimeException('AWS should be datacenter');
});
t('Google IP detected as datacenter', function() use ($intel) {
    $v = $intel->enrich(new CloakVisitor('35.1.2.3'));
    if (!$v->isDatacenter) throw new RuntimeException('GCP should be datacenter');
});
t('Residential IP not datacenter', function() use ($intel) {
    $v = $intel->enrich(new CloakVisitor('203.0.113.5'));
    if ($v->isDatacenter) throw new RuntimeException('Residential should not be datacenter');
});

echo "\n══════════════════════════════════════════\n";
echo "  Cloak: $p passed, $f failed\n";
echo "══════════════════════════════════════════\n";
exit($f ? 1 : 0);
