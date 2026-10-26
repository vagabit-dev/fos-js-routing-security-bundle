# FOSJsRoutingSecurityBundle

[![Latest Version](https://img.shields.io/badge/version1.0.0-alpha1-blue.svg)](https://packagist.org/packages/vagabit/fos-js-routing-security-bundle)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Extends [FOSJsRoutingBundle](https://github.com/FriendsOfSymfony/FOSJsRoutingBundle) to dynamically filter and serve exposed routes based on user roles and permissions in Symfony 6.4 / 7.x / 8.x.

Current version: **`1.0.0-alpha1`**


The fundamental purpose of FOSJsRoutingBundle is to maximize developer productivity by 
eliminating manual URL hardcoding in JavaScript. 
It brings Symfony’s route generation mechanism (`Routing.generate('route_name', { id: 1 })`) 
directly to frontend applications.

Standard FOSJsRouting operates on a public, all-or-nothing model. Any route marked `expose: 
true` is exposed to every visitor. This presents a clear security and efficiency drawback: 
internal and administrative endpoints (path structures, parameter schemas, and route names) are leaked 
to unauthenticated users who cannot use them, increasing the attack surface without providing 
any functional benefit.

This extension optimizes the system by aligning route delivery with actual user utility and access permissions:
1. Automated Route Filtering: for every exposed route, it evaluates Symfony’s security.yaml access_control rules and custom route-level role / roles options against the current user's security token.
2. Information Minimization: Users only receive route definitions for endpoints they are actually authorized to access. Anonymous users receive only public routes; administrative routes are only transmitted to users holding ROLE_ADMIN.
3. Security and Payload Reduction: Removing inaccessible routes shrinks payload size across the wire and prevents reconnaissance on sensitive backend structures.

## Features

- **Zero-Configuration Activation**: Automatically registers and decorates `fos_js_routing.extractor` as soon as the bundle is enabled in `bundles.php`—no manual service wiring in `services.yaml` needed.
- **Centralized Security Access Control**: Intercepts route extraction and matches route paths against `security.yaml` `access_control` rules via `Symfony\Component\Security\Http\AccessMapInterface`.
- **Single & Multiple Role Route Options**: Supports declaring single (`role`) or multiple (`roles`) required roles directly on the route definition (e.g. `options: ['expose' => true, 'role' => 'ROLE_MANAGER']` or `options: ['expose' => true, 'roles' => ['ROLE_ADMIN', 'ROLE_MANAGER']]`).
- **Role-Based Server-Side Caching**: Controller endpoint (`/js-routes.json`) caches route definitions keyed by the user's role combination (`js_routes_<hash>`), isolating cache entries per role configuration without repetitive path matching on every request.
- **Configurable HTTP Cache & Security Headers**: Secure by default with `private, no-store` headers, with configurable browser caching (`max_age`), revalidation (`must_revalidate`), public/private visibility, and conditional ETag handling (`304 Not Modified`).

---

## Installation & Setup

### 1. Install via Composer

```bash
composer require vagabit/fos-js-routing-security-bundle:^1.0.0-alpha1
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

### 3. Configure 

#### security.yaml

If you haven't already done so configure routes role access in `security.yaml`,
the bundle will use it to know which routes to serve by user role.

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin, roles: ROLE_ADMIN }
        - { path: ^/account, roles: ROLE_USER }
```

#### Exposing Routes with Options

Alternatively (or additionally) routes can be mae available to roles with the custom `role` option.

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

### 4. Usage

Can be used in almost all ways indicated in [FOSJsRouting usage page](https://github.com/FriendsOfSymfony/FOSJsRoutingBundle/blob/master/Resources/doc/usage.rst) (see note below!).

> **Note:** Given the dynamic way of serving the js routes by role:
>    * routes can not be dumped with `fos:js-routing:dump` command
>    * routes can not be used with Webpack plugin (`FosRouting`)


### 5. Configure Cache Lifetime, Prefix, Tag & HTTP Cache (Optional)

Generating js routes json on each request can be a heavy task especially for a complex application,
for this reason the bundle provides a specific cache system.

You can configure server-side and HTTP response caching behavior in your Symfony configuration (`config/packages/fos_js_routing_security.yaml`):

```yaml
# config/packages/fos_js_routing_security.yaml
fos_js_routing_security:
    # Server-side Cache TTL in seconds (integer or null).
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

    # HTTP Client / Browser Cache-Control settings for /js-routes.json
    http_cache:
        # Mark response as private (recommended for user-specific routes, default: true)
        private: true
        
        # Disallow storage completely with no-store (default: true)
        no_store: true
        
        # Max-age in seconds for browser caching when no_store is false (default: 0)
        max_age: 0
        
        # Require cache revalidation before using cached entry (default: false)
        must_revalidate: false
        
        # Enable ETag header generation and 304 Not Modified conditional responses (default: false)
        etag: false
```

#### HTTP Cache Configurations

Depending on your application's architecture and performance requirements, you can adjust `http_cache`:

* **Maximum Security & Session Isolation (Default)**:
  `private: true, no_store: true` prevents storing routes in browser or intermediate caches, ensuring role changes or logouts take effect instantly.
* **Short-Lived Browser Cache**:
  `private: true, no_store: false, max_age: 300` caches route definitions privately in the user's browser for 5 minutes, eliminating repeat network requests during active navigation.
* **Conditional Validation via ETag (`304 Not Modified`)**:
  `private: true, no_store: false, etag: true, must_revalidate: true` allows the browser to revalidate via `If-None-Match`. On match, the server returns an empty `304 Not Modified` payload without transferring route data.

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


---

## Running Tests

Run the test suite using Docker Compose without needing a local PHP environment:

```bash
# Run tests
docker compose run --rm test

# Or build and run in one step
docker compose up --build
```

Or directly with standalone Docker:

```bash
# Build the test image
docker build -t fos-js-routing-security-bundle-test .

# Run the test suite
docker run --rm -v "$(pwd):/app" -w /app fos-js-routing-security-bundle-test vendor/bin/phpunit
```

---

## Code Quality & Style

This project follows Symfony coding standards via `.editorconfig` and `PHP CS Fixer`.

### Using Docker Compose

```bash
# Check code style
docker compose run --rm --entrypoint composer test cs-check

# Automatically fix code style
docker compose run --rm --entrypoint composer test cs-fix
```

Or directly via PHP CS Fixer binary:

```bash
# Check code style with diff
docker compose run --rm --entrypoint vendor/bin/php-cs-fixer test fix --dry-run --diff

# Automatically fix code style
docker compose run --rm --entrypoint vendor/bin/php-cs-fixer test fix
```

### Using Standalone Docker

```bash
# Check code style
docker run --rm -v "$(pwd):/app" -w /app fos-js-routing-security-bundle-test vendor/bin/php-cs-fixer fix --dry-run --diff

# Automatically fix code style
docker run --rm -v "$(pwd):/app" -w /app fos-js-routing-security-bundle-test vendor/bin/php-cs-fixer fix
```

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
