<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Service;

use FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface;
use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\AccessMapInterface;

class RoleFilteredJsRoutesExtractor implements ExposedRoutesExtractorInterface
{
    public function __construct(
        private readonly ExposedRoutesExtractorInterface $innerExtractor,
        private readonly AuthorizationCheckerInterface $authChecker,
        private readonly AccessMapInterface $accessMap
    ) {
    }

    public function getInnerExtractor(): ExposedRoutesExtractorInterface
    {
        return $this->innerExtractor;
    }

    public function getRoutes(): RouteCollection
    {
        $allExposedRoutes = $this->innerExtractor->getRoutes();
        $filteredCollection = new RouteCollection();

        foreach ($allExposedRoutes->all() as $name => $route) {
            if ($this->isGrantedForRoute($route)) {
                $filteredCollection->add($name, $route);
            }
        }

        return $filteredCollection;
    }

    /**
     * Alias for getRoutes() to support callers using getExposedRoutes().
     */
    public function getExposedRoutes(): RouteCollection
    {
        return $this->getRoutes();
    }

    public function isRouteExposed(Route $route, string $name): bool
    {
        if (!$this->innerExtractor->isRouteExposed($route, $name)) {
            return false;
        }

        return $this->isGrantedForRoute($route);
    }

    /**
     * Check whether the current user is authorized to access the given route.
     */
    public function isGrantedForRoute(Route $route): bool
    {
        // 1. Check custom "role" / "roles" option configured on the route
        $roleOption = $route->getOption('role') ?? $route->getOption('roles');
        if (null !== $roleOption && false !== $roleOption && '' !== $roleOption) {
            $roles = is_array($roleOption) ? $roleOption : [$roleOption];
            $isGranted = false;

            foreach ($roles as $role) {
                if ($this->authChecker->isGranted($role)) {
                    $isGranted = true;
                    break;
                }
            }

            if (!$isGranted) {
                return false;
            }
        }

        // 2. Check security.yaml access_control definitions matching this route
        $methods = $route->getMethods();
        $method = !empty($methods) ? $methods[0] : 'GET';
        $dummyRequest = Request::create($route->getPath(), $method);

        if ($route->getHost()) {
            $dummyRequest->headers->set('host', $route->getHost());
        }

        $schemes = $route->getSchemes();
        if (!empty($schemes)) {
            $dummyRequest->server->set('HTTPS', in_array('https', $schemes, true) ? 'on' : 'off');
        }

        [$attributes] = $this->accessMap->getPatterns($dummyRequest);

        if (null !== $attributes && count($attributes) > 0) {
            $isGranted = false;

            foreach ($attributes as $attribute) {
                if ($this->authChecker->isGranted($attribute)) {
                    $isGranted = true;
                    break;
                }
            }

            if (!$isGranted) {
                return false;
            }
        }

        return true;
    }

    public function getBaseUrl(): string
    {
        return $this->innerExtractor->getBaseUrl();
    }

    public function getPrefix(string $locale): string
    {
        return $this->innerExtractor->getPrefix($locale);
    }

    public function getHost(): string
    {
        return $this->innerExtractor->getHost();
    }

    public function getPort(): ?string
    {
        return $this->innerExtractor->getPort();
    }

    public function getScheme(): string
    {
        return $this->innerExtractor->getScheme();
    }

    public function getCachePath(?string $locale): string
    {
        return $this->innerExtractor->getCachePath($locale);
    }

    /**
     * @return ResourceInterface[]
     */
    public function getResources(): array
    {
        return $this->innerExtractor->getResources();
    }
}
