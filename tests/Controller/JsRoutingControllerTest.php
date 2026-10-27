<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Tests\Controller;

use FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Vagabit\FOSJsRoutingSecurityBundle\Controller\JsRoutingController;

class JsRoutingControllerTest extends TestCase
{
    public function testGetRoutesForAnonymousUser(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $routeCollection = new RouteCollection();
        $routeCollection->add('public_home', new Route('/public/home', [], [], ['expose' => true]));

        $extractor->method('getRoutes')->willReturn($routeCollection);
        $extractor->method('getBaseUrl')->willReturn('');
        $extractor->method('getPrefix')->willReturn('');
        $extractor->method('getHost')->willReturn('localhost');
        $extractor->method('getPort')->willReturn('80');
        $extractor->method('getScheme')->willReturn('http');

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with(
                $this->callback(function (string $key) {
                    return str_starts_with($key, 'js_routes_');
                }),
                $this->anything()
            )
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);

                return $callback($item);
            });

        $controller = new JsRoutingController();

        $request = Request::create('/js-routes.json');
        $response = $controller->getRoutes($request, $extractor, $cache);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));

        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('routes', $data);
        $this->assertArrayHasKey('public_home', $data['routes']);
    }

    public function testGetRoutesForAuthenticatedUserWithContainer(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $routeCollection = new RouteCollection();
        $routeCollection->add('admin_dashboard', new Route('/admin/dashboard', [], [], ['expose' => true]));

        $extractor->method('getRoutes')->willReturn($routeCollection);
        $extractor->method('getBaseUrl')->willReturn('');
        $extractor->method('getPrefix')->willReturn('');
        $extractor->method('getHost')->willReturn('localhost');
        $extractor->method('getPort')->willReturn('80');
        $extractor->method('getScheme')->willReturn('http');

        $user = new InMemoryUser('admin', 'password', ['ROLE_ADMIN']);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('security.token_storage', $tokenStorage);

        $cache = $this->createMock(CacheInterface::class);
        $computedKey = '';
        $cache->expects($this->once())
            ->method('get')
            ->with(
                $this->callback(function (string $key) use (&$computedKey) {
                    $computedKey = $key;
                    // Hash of ROLE_ADMIN
                    $expectedHash = md5('ROLE_ADMIN');

                    return $key === 'js_routes_'.$expectedHash;
                }),
                $this->anything()
            )
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);

                return $callback($item);
            });

        $controller = new JsRoutingController();
        $controller->setContainer($container);

        $request = Request::create('/js-routes.json');
        $response = $controller->getRoutes($request, $extractor, $cache);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('admin_dashboard', $data['routes']);
    }

    public function testGetRoutesWithConfiguredTtlAndTag(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $extractor->method('getRoutes')->willReturn(new RouteCollection());
        $extractor->method('getBaseUrl')->willReturn('');
        $extractor->method('getPrefix')->willReturn('');
        $extractor->method('getHost')->willReturn('localhost');
        $extractor->method('getPort')->willReturn('80');
        $extractor->method('getScheme')->willReturn('http');

        $item = $this->createMock(ItemInterface::class);
        $item->expects($this->once())
            ->method('expiresAfter')
            ->with(1800);
        $item->expects($this->once())
            ->method('tag')
            ->with('custom_tag');

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with($this->callback(fn ($k) => str_starts_with($k, 'custom_prefix_')), $this->anything())
            ->willReturnCallback(fn ($key, $callback) => $callback($item));

        $controller = new JsRoutingController(1800, 'custom_prefix_', 'custom_tag');
        $request = Request::create('/js-routes.json');
        $response = $controller->getRoutes($request, $extractor, $cache);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testGetRoutesWithNeverExpireTtl(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $extractor->method('getRoutes')->willReturn(new RouteCollection());
        $extractor->method('getBaseUrl')->willReturn('');
        $extractor->method('getPrefix')->willReturn('');
        $extractor->method('getHost')->willReturn('localhost');
        $extractor->method('getPort')->willReturn('80');
        $extractor->method('getScheme')->willReturn('http');

        $item = $this->createMock(ItemInterface::class);
        // expiresAfter should NOT be called when cacheTtl is null (never expire)
        $item->expects($this->never())
            ->method('expiresAfter');

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(fn ($key, $callback) => $callback($item));

        $controller = new JsRoutingController(null);
        $request = Request::create('/js-routes.json');
        $response = $controller->getRoutes($request, $extractor, $cache);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testGetRoutesWithZeroTtlExpiresImmediately(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $extractor->method('getRoutes')->willReturn(new RouteCollection());
        $extractor->method('getBaseUrl')->willReturn('');
        $extractor->method('getPrefix')->willReturn('');
        $extractor->method('getHost')->willReturn('localhost');
        $extractor->method('getPort')->willReturn('80');
        $extractor->method('getScheme')->willReturn('http');

        $item = $this->createMock(ItemInterface::class);
        $item->expects($this->once())
            ->method('expiresAfter')
            ->with(0);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(fn ($key, $callback) => $callback($item));

        $controller = new JsRoutingController(0, 'js_routes_', null);
        $request = Request::create('/js-routes.json');
        $response = $controller->getRoutes($request, $extractor, $cache);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTagAwareCacheIntegrationAndInvalidation(): void
    {
        $arrayAdapter = new \Symfony\Component\Cache\Adapter\ArrayAdapter();
        $tagAwareCache = new \Symfony\Component\Cache\Adapter\TagAwareAdapter($arrayAdapter);

        $callCount = 0;
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $extractor->method('getRoutes')->willReturnCallback(function () use (&$callCount) {
            ++$callCount;
            $collection = new RouteCollection();
            $collection->add('route_'.$callCount, new Route('/route/'.$callCount, [], [], ['expose' => true]));

            return $collection;
        });
        $extractor->method('getBaseUrl')->willReturn('');
        $extractor->method('getPrefix')->willReturn('');
        $extractor->method('getHost')->willReturn('localhost');
        $extractor->method('getPort')->willReturn('80');
        $extractor->method('getScheme')->willReturn('http');

        $controller = new JsRoutingController(3600, 'js_routes_', 'fos_js_routing_security');
        $request = Request::create('/js-routes.json');

        // First call: extracts routes from extractor (callCount = 1)
        $res1 = $controller->getRoutes($request, $extractor, $tagAwareCache);
        $data1 = json_decode((string) $res1->getContent(), true);
        $this->assertSame(1, $callCount);
        $this->assertArrayHasKey('route_1', $data1['routes']);

        // Second call: served from cache (callCount remains 1)
        $res2 = $controller->getRoutes($request, $extractor, $tagAwareCache);
        $data2 = json_decode((string) $res2->getContent(), true);
        $this->assertSame(1, $callCount);
        $this->assertArrayHasKey('route_1', $data2['routes']);

        // Invalidate by cache tag
        $tagAwareCache->invalidateTags(['fos_js_routing_security']);

        // Third call: cache is invalidated, extractor called again (callCount becomes 2)
        $res3 = $controller->getRoutes($request, $extractor, $tagAwareCache);
        $data3 = json_decode((string) $res3->getContent(), true);
        $this->assertSame(2, $callCount);
        $this->assertArrayHasKey('route_2', $data3['routes']);
    }

    public function testCustomHttpCacheHeadersWithBrowserCaching(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $extractor->method('getRoutes')->willReturn(new RouteCollection());
        $extractor->method('getBaseUrl')->willReturn('');
        $extractor->method('getPrefix')->willReturn('');
        $extractor->method('getHost')->willReturn('localhost');
        $extractor->method('getPort')->willReturn('80');
        $extractor->method('getScheme')->willReturn('http');

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn ($k, $cb) => $cb($this->createMock(ItemInterface::class)));

        $controller = new JsRoutingController(
            cacheTtl: 3600,
            cachePrefix: 'js_routes_',
            cacheTag: 'fos_js_routing_security',
            tokenStorage: null,
            httpCache: [
                'private' => true,
                'no_store' => false,
                'max_age' => 300,
                'must_revalidate' => true,
                'etag' => false,
            ]
        );

        $request = Request::create('/js-routes.json');
        $response = $controller->getRoutes($request, $extractor, $cache);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
        $this->assertSame('300', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testPublicHttpCacheHeaders(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $extractor->method('getRoutes')->willReturn(new RouteCollection());
        $extractor->method('getBaseUrl')->willReturn('');
        $extractor->method('getPrefix')->willReturn('');
        $extractor->method('getHost')->willReturn('localhost');
        $extractor->method('getPort')->willReturn('80');
        $extractor->method('getScheme')->willReturn('http');

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn ($k, $cb) => $cb($this->createMock(ItemInterface::class)));

        $controller = new JsRoutingController(
            cacheTtl: 3600,
            cachePrefix: 'js_routes_',
            cacheTag: 'fos_js_routing_security',
            tokenStorage: null,
            httpCache: [
                'private' => false,
                'no_store' => false,
                'max_age' => 600,
                'must_revalidate' => false,
                'etag' => false,
            ]
        );

        $request = Request::create('/js-routes.json');
        $response = $controller->getRoutes($request, $extractor, $cache);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertFalse($response->headers->hasCacheControlDirective('private'));
        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('600', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testEtagGenerationAnd304NotModified(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $routes = new RouteCollection();
        $routes->add('home', new Route('/home', [], [], ['expose' => true]));
        $extractor->method('getRoutes')->willReturn($routes);
        $extractor->method('getBaseUrl')->willReturn('');
        $extractor->method('getPrefix')->willReturn('');
        $extractor->method('getHost')->willReturn('localhost');
        $extractor->method('getPort')->willReturn('80');
        $extractor->method('getScheme')->willReturn('http');

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn ($k, $cb) => $cb($this->createMock(ItemInterface::class)));

        $controller = new JsRoutingController(
            cacheTtl: 3600,
            cachePrefix: 'js_routes_',
            cacheTag: 'fos_js_routing_security',
            tokenStorage: null,
            httpCache: [
                'private' => true,
                'no_store' => false,
                'max_age' => 0,
                'must_revalidate' => true,
                'etag' => true,
            ]
        );

        // Initial request without ETag -> returns 200 with ETag header
        $request1 = Request::create('/js-routes.json');
        $response1 = $controller->getRoutes($request1, $extractor, $cache);

        $this->assertSame(200, $response1->getStatusCode());
        $etag = $response1->getEtag();
        $this->assertNotNull($etag);
        $this->assertNotEmpty($etag);

        // Conditional request with matching If-None-Match -> returns 304 Not Modified
        $request2 = Request::create('/js-routes.json', 'GET', [], [], [], [
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);
        $response2 = $controller->getRoutes($request2, $extractor, $cache);

        $this->assertSame(304, $response2->getStatusCode());
    }
}
