# FOSJsRoutingSecurityBundle

[![Latest Version](https://img.shields.io/badge/version1.0.0-alpha1-blue.svg)](https://packagist.org/packages/vagabit/fos-js-routing-security-bundle)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Extends [FOSJsRoutingBundle](https://github.com/FriendsOfSymfony/FOSJsRoutingBundle) to dynamically filter and serve exposed routes based on user roles and permissions in Symfony 6.4 / 7.x / 8.x.

Current version: **`1.0.0-alpha1`**

## Features

- **Zero-Configuration Activation**: Automatically registers and decorates `fos_js_routing.extractor` as soon as the bundle is enabled in `bundles.php`—no manual service wiring in `services.yaml` needed.
- **Centralized Security Access Control**: Intercepts route extraction and matches route paths against `security.yaml` `access_control` rules via `Symfony\Component\Security\Http\AccessMapInterface`.
- **Single & Multiple Role Route Options**: Supports declaring single (`role`) or multiple (`roles`) required roles directly on the route definition (e.g. `options: ['expose' => true, 'role' => 'ROLE_MANAGER']` or `options: ['expose' => true, 'roles' => ['ROLE_ADMIN', 'ROLE_MANAGER']]`).
- **Role-Based Server-Side Caching**: Controller endpoint (`/js-routes.json`) caches route definitions keyed by the user's role combination (`js_routes_<hash>`), isolating cache entries per role configuration without repetitive path matching on every request.
- **Cache-Control Security Headers**: Returns `private, no-store` response headers to ensure dynamic routes are never stored in public/shared browser caches.

---

## Installation & Setup

### 1. Install via Composer

```bash
composer require vagabit/fos-js-routing-security-bundle:^0.1.0-alpha1
```

### 2. Enable the Bundle

If you use Symfony Flex, the bundle is enabled automatically upon installation. Otherwise, add it to `config/bundles.php`:

```php
// config/bundles.php
return [
    // ...
    FOS\JsRoutingBundle\FOSJsRoutingBundle::class => ['all' => true],
    App\FOSJsRoutingSecurityBundle::class => ['all' => true],
];
```

> **Note:** The bundle automatically registers and activates `RoleFilteredJsRoutesExtractor` as a decorator of `fos_js_routing.extractor`, along with the cache warmer and `/js-routes.json` controller endpoint. No manual service configuration in `config/services.yaml` is required.

### 3. Configure `security.yaml`

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin, roles: ROLE_ADMIN }
        - { path: ^/account, roles: ROLE_USER }
```

### 4. Exposing Routes with Options

#### Via `security.yaml` Matching
```php
#[Route('/admin/dashboard', name: 'admin_dashboard', options: ['expose' => true])]
public function dashboard(): Response
{
    // ...
}
```

#### Via Custom Single `role` Option
```php
#[Route('/manager/reports', name: 'manager_reports', options: ['expose' => true, 'role' => 'ROLE_MANAGER'])]
public function reports(): Response
{
    // ...
}
```

#### Via Custom Multiple `roles` Option
You can supply an array of roles using either the `roles` or `role` option. If the authenticated user has **at least one** of the specified roles, the route is exposed:

```php
#[Route(
    '/staff/reports',
    name: 'staff_reports',
    options: [
        'expose' => true,
        'roles'  => ['ROLE_ADMIN', 'ROLE_MANAGER'], // Accessible to either ROLE_ADMIN or ROLE_MANAGER
    ]
)]
public function staffReports(): Response
{
    // ...
}
```

### 5. Configure Cache Lifetime, Prefix & Tag (Optional)

You can configure cache behavior in your Symfony configuration (`config/packages/fos_js_routing_security.yaml`):

```yaml
# config/packages/fos_js_routing_security.yaml
fos_js_routing_security:
    # Cache TTL in seconds (integer or null).
    # - Positive integer (> 0): Cache expires after N seconds (e.g. 3600 for 1 hour).
    # - null (or ~ or "never"): Indefinite cache lifetime (entries never expire automatically).
    # - 0: Immediate expiration (disables caching / fresh extraction on every request).
    cache_ttl: 3600
    
    # Custom cache key prefix prepended to role hashes (default: 'js_routes_')
    cache_prefix: 'js_routes_'
    
    # Cache tag applied to route items for selective tag-based invalidation (default: 'fos_js_routing_security')
    # Set to null or '' to disable cache tagging.
    cache_tag: 'fos_js_routing_security'

    # Role combinations to pre-warm in cache during cache:warmup or via console command
    warmup_roles:
        - ROLE_ANONYMOUS
        - ROLE_USER
        - [ROLE_ADMIN, ROLE_USER]
```

#### Cache TTL Semantics (`cache_ttl: ?int`)

| Value | Behavior | Description |
| :--- | :--- | :--- |
| `> 0` (e.g. `3600`) | **Time-to-Live (TTL)** | Routes for each role set are cached and expire after $N$ seconds. |
| `null` / `~` / `"never"` | **Never Expire** | Indefinite retention. Routes remain cached until explicitly invalidated or flushed. Ideal for production with tag invalidation. |
| `0` | **Immediate Expiry / No-Cache** | Forces route extraction on every request without retaining cache items. |

#### Cache Warmer

The bundle includes `App\Service\JsRoutesCacheWarmer`, which pre-populates role-filtered routes in your application cache so end users always hit a warm cache on first request:

##### 1. Automatic Warmup on `cache:warmup`
The cache warmer implements Symfony's `CacheWarmerInterface` (`kernel.cache_warmer`) and is executed automatically whenever `cache:warmup` or `cache:clear` runs:

```bash
php bin/console cache:warmup
```

##### 2. On-Demand Warmup Command
You can also warm up routes on-demand (e.g. during CI/CD deployment pipelines):

```bash
# Warm up all configured role combinations
php bin/console fos:js-routing:warmup

# Warm up specific role combinations on the fly
php bin/console fos:js-routing:warmup --role=ROLE_ADMIN --role=ROLE_USER
```

#### Selective Cache Invalidation

When using a Symfony tag-aware cache pool (such as `TagAwareAdapter`, Redis, or Memcached), all cached role combinations can be invalidated simultaneously when routing or security rules change without clearing the entire application cache:

**Via PHP Code:**
```php
use Symfony\Contracts\Cache\TagAwareCacheInterface;

// Invalidate all role route caches at once
$cache->invalidateTags(['fos_js_routing_security']);
```

**Via Symfony Console:**
```bash
php bin/console cache:pool:invalidate-tags fos_js_routing_security
```

### 5. Fetch Routes in Frontend JavaScript

```javascript
import Routing from 'router'; // FOSJsRouting client library

fetch('/js-routes.json')
  .then(response => response.json())
  .then(data => {
    Routing.setData(data);
    
    // Only available if the authenticated user has the required role
    console.log(Routing.generate('admin_dashboard'));
  });
```

---

## Running Tests

### Using Docker (Recommended)

Run the test suite using Docker Compose without needing a local PHP 8.1+ environment:

```bash
# Run tests
docker compose run --rm test

# Or build and run in one step
docker compose up --build
```

You can also run directly with Docker:

```bash
# Build the test image
docker build -t fos-js-routing-security-bundle-test .

# Run the test suite
docker run --rm -v "$(pwd):/app" -w /app fos-js-routing-security-bundle-test vendor/bin/phpunit
```

### Running Locally

```bash
composer install
./vendor/bin/phpunit
```

---

## Code Quality & Style

This project follows Symfony coding standards via `.editorconfig` and `PHP CS Fixer`.

```bash
# Check code style
vendor/bin/php-cs-fixer fix --dry-run --diff

# Automatically fix code style
vendor/bin/php-cs-fixer fix
```

Or via Composer scripts:

```bash
composer cs-check
composer cs-fix
```

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
