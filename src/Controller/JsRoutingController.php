<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Controller;

use FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface;
use FOS\JsRoutingBundle\Response\RoutesResponse;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class JsRoutingController
{
    private ?ContainerInterface $container = null;

    /**
     * @param int|null $cacheTtl Cache TTL in seconds. Set to null for indefinite cache lifetime (never expire), or 0 for immediate expiration.
     * @param string $cachePrefix prefix prepended to role-hashed cache keys
     * @param string|null $cacheTag cache tag applied to cache items for selective invalidation (null to disable tagging)
     * @param TokenStorageInterface|null $tokenStorage optional token storage for retrieving user roles
     * @param array{private?: bool, no_store?: bool, max_age?: int, must_revalidate?: bool, etag?: bool} $httpCache HTTP cache settings for responses
     */
    public function __construct(
        private readonly ?int $cacheTtl = 3600,
        private readonly string $cachePrefix = 'js_routes_',
        private readonly ?string $cacheTag = 'fos_js_routing_security',
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly array $httpCache = [
            'private' => true,
            'no_store' => true,
            'max_age' => 0,
            'must_revalidate' => false,
            'etag' => false,
        ]
    ) {
    }

    public function setContainer(?ContainerInterface $container): ?ContainerInterface
    {
        $previous = $this->container;
        $this->container = $container;

        return $previous;
    }

    #[Route('/vagabit-secure-js-routes.json', name: 'vagabit_secure_js_routes', methods: ['GET'])]
    public function getRoutes(
        Request $request,
        ExposedRoutesExtractorInterface $extractor,
        CacheInterface $cache
    ): JsonResponse {
        $tokenStorage = $this->tokenStorage;
        if (null === $tokenStorage && null !== $this->container && $this->container->has('security.token_storage')) {
            $tokenStorage = $this->container->get('security.token_storage');
        }

        $user = $tokenStorage?->getToken()?->getUser();

        // 1. Compute a cache key based strictly on user roles
        $roles = $user && method_exists($user, 'getRoles') ? $user->getRoles() : ['ROLE_ANONYMOUS'];
        sort($roles); // Normalize order to reuse cache across users with identical roles
        $cacheKey = $this->cachePrefix.md5(implode('_', $roles));

        // 2. Fetch from Symfony Cache or compute if cache miss
        $routesData = $cache->get($cacheKey, function (ItemInterface $item) use ($extractor) {
            if (null !== $this->cacheTtl) {
                $item->expiresAfter($this->cacheTtl);
            }

            if (null !== $this->cacheTag && '' !== $this->cacheTag && method_exists($item, 'tag')) {
                try {
                    $item->tag($this->cacheTag);
                } catch (\LogicException) {
                    // Ignore if the cache pool does not support tagging
                }
            }

            $routes = method_exists($extractor, 'getRoutes') ? $extractor->getRoutes() : $extractor->getExposedRoutes();

            $routesResponse = new RoutesResponse(
                $extractor->getBaseUrl(),
                $routes,
                $extractor->getPrefix(''),
                $extractor->getHost(),
                $extractor->getPort(),
                $extractor->getScheme()
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

        // 3. Return JSON with browser security and HTTP cache headers
        $response = new JsonResponse($routesData);

        if (!empty($this->httpCache['private'])) {
            $response->setPrivate();
        } else {
            $response->setPublic();
        }

        if (!empty($this->httpCache['no_store'])) {
            $response->headers->addCacheControlDirective('no-store', true);
        } else {
            $response->headers->removeCacheControlDirective('no-store');
            if (isset($this->httpCache['max_age'])) {
                $response->setMaxAge($this->httpCache['max_age']);
            }
            if (!empty($this->httpCache['must_revalidate'])) {
                $response->headers->addCacheControlDirective('must-revalidate', true);
            }
        }

        if (!empty($this->httpCache['etag'])) {
            $response->setEtag(md5((string) $response->getContent()));
            $response->isNotModified($request);
        }

        return $response;
    }
}
