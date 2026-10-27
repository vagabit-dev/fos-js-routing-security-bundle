<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Service;

use FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface;
use FOS\JsRoutingBundle\Response\RoutesResponse;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class JsRoutesCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param array<int, array<int, string>|string> $warmupRoles
     */
    public function __construct(
        private readonly ExposedRoutesExtractorInterface $extractor,
        private readonly CacheInterface $cache,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly array $warmupRoles = [['ROLE_ANONYMOUS']],
        private readonly ?int $cacheTtl = 3600,
        private readonly string $cachePrefix = 'js_routes_',
        private readonly ?string $cacheTag = 'fos_js_routing_security'
    ) {
    }

    /**
     * Warms up the cache for all configured role combinations.
     *
     * @return string[] Preload class/file list for PHP 7.4+
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->warmUpAll();

        return [];
    }

    /**
     * Warms up the cache for a specific list of role combinations (or configured ones if null).
     *
     * @param array<int, array<int, string>|string>|null $roleCombinations
     *
     * @return array<string, array<string, mixed>> Map of cacheKey => cached routes data
     */
    public function warmUpAll(?array $roleCombinations = null): array
    {
        $combinations = $roleCombinations ?? $this->warmupRoles;
        $results = [];

        foreach ($combinations as $roles) {
            $rolesArray = is_array($roles) ? $roles : [$roles];
            $normalizedRoles = $this->normalizeRoles($rolesArray);
            $cacheKey = $this->getCacheKey($normalizedRoles);
            $results[$cacheKey] = $this->warmUpRoleCombination($normalizedRoles);
        }

        return $results;
    }

    /**
     * Warms up the cache for a single role combination.
     *
     * @param array<int, string> $roles
     *
     * @return array<string, mixed> The generated routes payload
     */
    public function warmUpRoleCombination(array $roles): array
    {
        $normalizedRoles = $this->normalizeRoles($roles);
        $cacheKey = $this->getCacheKey($normalizedRoles);

        $originalToken = $this->tokenStorage?->getToken();

        try {
            if (null !== $this->tokenStorage) {
                if ($normalizedRoles === ['ROLE_ANONYMOUS']) {
                    $this->tokenStorage->setToken(new NullToken());
                } else {
                    $user = new InMemoryUser('warmup_user', null, $normalizedRoles);
                    $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $normalizedRoles));
                }
            }

            // Force cache recreation by deleting previous entry if exists
            if (method_exists($this->cache, 'delete')) {
                $this->cache->delete($cacheKey);
            }

            return $this->cache->get($cacheKey, function (ItemInterface $item) {
                if (null !== $this->cacheTtl) {
                    $item->expiresAfter($this->cacheTtl);
                }

                if (null !== $this->cacheTag && '' !== $this->cacheTag && method_exists($item, 'tag')) {
                    try {
                        $item->tag($this->cacheTag);
                    } catch (\LogicException) {
                        // Ignore if cache pool does not support tagging
                    }
                }

                $routes = method_exists($this->extractor, 'getRoutes')
                    ? $this->extractor->getRoutes()
                    : $this->extractor->getExposedRoutes();

                $routesResponse = new RoutesResponse(
                    $this->extractor->getBaseUrl(),
                    $routes,
                    $this->extractor->getPrefix(''),
                    $this->extractor->getHost(),
                    $this->extractor->getPort(),
                    $this->extractor->getScheme()
                );

                return [
                    'base_url' => $routesResponse->getBaseUrl(),
                    'routes' => $routesResponse->getRoutes(),
                    'prefix' => $routesResponse->getPrefix(),
                    'host' => $routesResponse->getHost(),
                    'port' => $routesResponse->getPort(),
                    'scheme' => $routesResponse->getScheme(),
                    'locale' => $routesResponse->getLocale(),
                ];
            });
        } finally {
            if (null !== $this->tokenStorage) {
                $this->tokenStorage->setToken($originalToken);
            }
        }
    }

    /**
     * Computes the deterministic cache key for a given set of roles.
     *
     * @param array<int, string> $roles
     */
    public function getCacheKey(array $roles): string
    {
        $normalized = $this->normalizeRoles($roles);

        return $this->cachePrefix.md5(implode('_', $normalized));
    }

    /**
     * Normalizes a role list (trims, deduplicates, sorts, and defaults empty to ROLE_ANONYMOUS).
     *
     * @param array<int, string> $roles
     *
     * @return array<int, string>
     */
    public function normalizeRoles(array $roles): array
    {
        $filtered = array_values(array_filter(
            array_unique($roles),
            fn ($role) => is_string($role) && '' !== trim($role)
        ));

        // If 'ROLE_ANONYMOUS' is present along with other specific roles, remove 'ROLE_ANONYMOUS'
        if (count($filtered) > 1 && in_array('ROLE_ANONYMOUS', $filtered, true)) {
            $filtered = array_values(array_filter($filtered, fn ($r) => 'ROLE_ANONYMOUS' !== $r));
        }

        if (empty($filtered)) {
            return ['ROLE_ANONYMOUS'];
        }

        sort($filtered);

        return $filtered;
    }

    public function isOptional(): bool
    {
        return true;
    }
}
