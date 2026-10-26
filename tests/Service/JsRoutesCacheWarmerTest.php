<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\JsRoutesCacheWarmer;
use FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class JsRoutesCacheWarmerTest extends TestCase
{
    public function testIsOptionalReturnsTrue(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $warmer = new JsRoutesCacheWarmer($extractor, $cache);

        $this->assertTrue($warmer->isOptional());
    }

    public function testNormalizeRoles(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $warmer = new JsRoutesCacheWarmer($extractor, $cache);

        // Empty array defaults to ROLE_ANONYMOUS
        $this->assertSame(['ROLE_ANONYMOUS'], $warmer->normalizeRoles([]));

        // Sorting & deduplication
        $this->assertSame(['ROLE_ADMIN', 'ROLE_USER'], $warmer->normalizeRoles(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_USER']));

        // ROLE_ANONYMOUS filtered out if real roles present
        $this->assertSame(['ROLE_USER'], $warmer->normalizeRoles(['ROLE_ANONYMOUS', 'ROLE_USER']));

        // Whitespace and empty elements filtered
        $this->assertSame(['ROLE_USER'], $warmer->normalizeRoles(['', '  ', 'ROLE_USER']));
    }

    public function testGetCacheKey(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $warmer = new JsRoutesCacheWarmer($extractor, $cache, null, [], 3600, 'custom_prefix_');

        $expectedKey = 'custom_prefix_'.md5('ROLE_ADMIN_ROLE_USER');
        $this->assertSame($expectedKey, $warmer->getCacheKey(['ROLE_USER', 'ROLE_ADMIN']));
    }

    public function testWarmUpAllWithDefaultRoles(): void
    {
        $routeCollection = new RouteCollection();
        $routeCollection->add('public_route', new Route('/public', [], [], ['expose' => true]));

        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $extractor->method('getRoutes')->willReturn($routeCollection);
        $extractor->method('getBaseUrl')->willReturn('');
        $extractor->method('getPrefix')->willReturn('');
        $extractor->method('getHost')->willReturn('localhost');
        $extractor->method('getPort')->willReturn('80');
        $extractor->method('getScheme')->willReturn('http');

        $item = $this->createMock(ItemInterface::class);
        $item->expects($this->once())
            ->method('expiresAfter')
            ->with(3600);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with('js_routes_'.md5('ROLE_ANONYMOUS'))
            ->willReturnCallback(fn ($key, $callback) => $callback($item));

        $tokenStorage = new TokenStorage();
        $warmer = new JsRoutesCacheWarmer(
            $extractor,
            $cache,
            $tokenStorage,
            [['ROLE_ANONYMOUS']],
            3600,
            'js_routes_',
            'fos_js_routing_security'
        );

        $results = $warmer->warmUpAll();

        $cacheKey = 'js_routes_'.md5('ROLE_ANONYMOUS');
        $this->assertArrayHasKey($cacheKey, $results);
        $this->assertSame('', $results[$cacheKey]['base_url']);
        $this->assertSame('localhost', $results[$cacheKey]['host']);
        $this->assertArrayHasKey('public_route', (array) $results[$cacheKey]['routes']);
    }

    public function testWarmUpRoleCombinationSetsAndRestoresToken(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $extractor->method('getRoutes')->willReturn(new RouteCollection());
        $extractor->method('getBaseUrl')->willReturn('');
        $extractor->method('getPrefix')->willReturn('');
        $extractor->method('getHost')->willReturn('localhost');
        $extractor->method('getPort')->willReturn('80');
        $extractor->method('getScheme')->willReturn('http');

        $item = $this->createMock(ItemInterface::class);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn ($key, $callback) => $callback($item));

        $tokenStorage = new TokenStorage();
        $originalUser = new InMemoryUser('original_user', null, ['ROLE_PREVIOUS']);
        $originalToken = new UsernamePasswordToken($originalUser, 'main', $originalUser->getRoles());
        $tokenStorage->setToken($originalToken);

        $warmer = new JsRoutesCacheWarmer(
            $extractor,
            $cache,
            $tokenStorage,
            [['ROLE_ADMIN']]
        );

        $warmer->warmUpRoleCombination(['ROLE_ADMIN']);

        // Verify the original token was restored
        $this->assertSame($originalToken, $tokenStorage->getToken());
    }

    public function testWarmUpReturnsEmptyArrayForWarmableInterface(): void
    {
        $extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $extractor->method('getRoutes')->willReturn(new RouteCollection());
        $extractor->method('getBaseUrl')->willReturn('');
        $extractor->method('getPrefix')->willReturn('');
        $extractor->method('getHost')->willReturn('localhost');
        $extractor->method('getPort')->willReturn('80');
        $extractor->method('getScheme')->willReturn('http');

        $item = $this->createMock(ItemInterface::class);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn ($key, $callback) => $callback($item));

        $warmer = new JsRoutesCacheWarmer($extractor, $cache, null, [['ROLE_ANONYMOUS']]);

        $result = $warmer->warmUp('/tmp/cache');
        $this->assertSame([], $result);
    }
}
