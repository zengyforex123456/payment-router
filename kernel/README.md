# converge/core v3.2

Converge infrastructure kernel — reusable across all Converge-based SaaS projects.

## Contents

| Namespace | Path | Provides |
|-----------|------|----------|
| `Converge\Core\Event` | `src/Core/Event/` | EventBus (typed domain events), Hook bridge |
| `Converge\Core\Hook` | `src/Core/Hook/` | WordPress-style actions + filters system |
| `Converge\Core\Module` | `src/Core/Module/` | ModuleLoader (topological sort), ModuleRegistry, Fabric |
| `Converge\Core\Helper` | `src/Core/Helper/` | DataEncoder (XSS-safe JSON encoding) |
| `Converge\Foundation\Resilience` | `src/Foundation/Resilience/` | CircuitBreaker, RetryHandler, SelfHealEngine, ShadowMode |
| `Converge\Foundation\Observability` | `src/Foundation/Observability/` | StructuredLogger, HealthChecker, AlertNotifier |
| `Converge\Foundation\System` | `src/Foundation/System/` | ConnectionManager, FeatureRegistry, SnapshotGenerator |
| `Converge\Security` | `src/Security/` | Auth (Argon2ID), DualAuth, RBAC (45 permissions), CSRF, SSRF, BotDetector |
| `Converge\I18n` | `src/I18n/` | Locale detection, `__()` translation function |
| `Converge\UI` | `src/UI/` | TDA component engine (LatteEngine, Badge, Button, Input, StatCard...) |

## Install

```bash
composer require converge/core:^3.2
```

## Development

```bash
git clone git@github.com:your-org/converge-core.git
cd converge-core
composer install
vendor/bin/phpunit
```

## Version Policy

- **3.x**: PHP 8.2+, Stimulus 3, Latte 3. Breaking changes only in major version.
- PSR-4 autoload, zero classmap.
- Production Docker: PHP-FPM 8.3 + Nginx + MySQL 8.0.
