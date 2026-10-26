<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RoleFilteredJsRoutesExtractor;
use FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\AccessMapInterface;

class RoleFilteredJsRoutesExtractorTest extends TestCase
{
    private $innerExtractor;
    private $authChecker;
    private $accessMap;
    private RoleFilteredJsRoutesExtractor $extractor;

    protected function setUp(): void
    {
        $this->innerExtractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $this->authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->accessMap = $this->createMock(AccessMapInterface::class);

        $this->extractor = new RoleFilteredJsRoutesExtractor(
            $this->innerExtractor,
            $this->authChecker,
            $this->accessMap
        );
    }

    public function testGetRoutesWithPublicRoute(): void
    {
        $routeCollection = new RouteCollection();
        $routeCollection->add('public_route', new Route('/public', [], [], ['expose' => true]));

        $this->innerExtractor->method('getRoutes')->willReturn($routeCollection);
        $this->accessMap->method('getPatterns')->willReturn([null, null]);

        $filtered = $this->extractor->getRoutes();

        $this->assertCount(1, $filtered);
        $this->assertNotNull($filtered->get('public_route'));
    }

    public function testGetRoutesWithCustomRoleOptionGranted(): void
    {
        $routeCollection = new RouteCollection();
        $routeCollection->add('manager_route', new Route('/manager', [], [], ['expose' => true, 'role' => 'ROLE_MANAGER']));

        $this->innerExtractor->method('getRoutes')->willReturn($routeCollection);
        $this->accessMap->method('getPatterns')->willReturn([null, null]);

        $this->authChecker->expects($this->once())
            ->method('isGranted')
            ->with('ROLE_MANAGER')
            ->willReturn(true);

        $filtered = $this->extractor->getRoutes();

        $this->assertCount(1, $filtered);
        $this->assertNotNull($filtered->get('manager_route'));
    }

    public function testGetRoutesWithCustomRoleOptionDenied(): void
    {
        $routeCollection = new RouteCollection();
        $routeCollection->add('manager_route', new Route('/manager', [], [], ['expose' => true, 'role' => 'ROLE_MANAGER']));

        $this->innerExtractor->method('getRoutes')->willReturn($routeCollection);
        $this->accessMap->method('getPatterns')->willReturn([null, null]);

        $this->authChecker->expects($this->once())
            ->method('isGranted')
            ->with('ROLE_MANAGER')
            ->willReturn(false);

        $filtered = $this->extractor->getRoutes();

        $this->assertCount(0, $filtered);
        $this->assertNull($filtered->get('manager_route'));
    }

    public function testGetRoutesWithCustomRolesArrayOption(): void
    {
        $routeCollection = new RouteCollection();
        $routeCollection->add('multi_role_route', new Route('/staff', [], [], [
            'expose' => true,
            'roles' => ['ROLE_ADMIN', 'ROLE_MANAGER'],
        ]));

        $this->innerExtractor->method('getRoutes')->willReturn($routeCollection);
        $this->accessMap->method('getPatterns')->willReturn([null, null]);

        $this->authChecker->method('isGranted')
            ->willReturnCallback(function (string $attribute) {
                return 'ROLE_MANAGER' === $attribute;
            });

        $filtered = $this->extractor->getRoutes();

        $this->assertCount(1, $filtered);
        $this->assertNotNull($filtered->get('multi_role_route'));
    }

    public function testGetRoutesWithAccessControlInSecurityYamlGranted(): void
    {
        $routeCollection = new RouteCollection();
        $routeCollection->add('admin_dashboard', new Route('/admin/dashboard', [], [], ['expose' => true]));

        $this->innerExtractor->method('getRoutes')->willReturn($routeCollection);

        $this->accessMap->expects($this->once())
            ->method('getPatterns')
            ->with($this->callback(function (Request $req) {
                return '/admin/dashboard' === $req->getPathInfo();
            }))
            ->willReturn([['ROLE_ADMIN'], null]);

        $this->authChecker->expects($this->once())
            ->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(true);

        $filtered = $this->extractor->getRoutes();

        $this->assertCount(1, $filtered);
        $this->assertNotNull($filtered->get('admin_dashboard'));
    }

    public function testGetRoutesWithAccessControlInSecurityYamlDenied(): void
    {
        $routeCollection = new RouteCollection();
        $routeCollection->add('admin_dashboard', new Route('/admin/dashboard', [], [], ['expose' => true]));

        $this->innerExtractor->method('getRoutes')->willReturn($routeCollection);

        $this->accessMap->expects($this->once())
            ->method('getPatterns')
            ->willReturn([['ROLE_ADMIN'], null]);

        $this->authChecker->expects($this->once())
            ->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(false);

        $filtered = $this->extractor->getRoutes();

        $this->assertCount(0, $filtered);
        $this->assertNull($filtered->get('admin_dashboard'));
    }

    public function testBothCustomRoleAndAccessMapRequired(): void
    {
        $routeCollection = new RouteCollection();
        $routeCollection->add('admin_audit', new Route('/admin/audit', [], [], [
            'expose' => true,
            'role' => 'ROLE_SUPER_ADMIN',
        ]));

        $this->innerExtractor->method('getRoutes')->willReturn($routeCollection);

        $this->accessMap->method('getPatterns')->willReturn([['ROLE_ADMIN'], null]);

        // If user has ROLE_ADMIN but not ROLE_SUPER_ADMIN, custom role fails
        $this->authChecker->method('isGranted')
            ->willReturnCallback(function (string $attribute) {
                return 'ROLE_ADMIN' === $attribute; // Not ROLE_SUPER_ADMIN
            });

        $filtered = $this->extractor->getRoutes();
        $this->assertCount(0, $filtered);
    }

    public function testProxyMethods(): void
    {
        $this->innerExtractor->method('getBaseUrl')->willReturn('/base');
        $this->innerExtractor->method('getPrefix')->with('en')->willReturn('en_');
        $this->innerExtractor->method('getHost')->willReturn('example.com');
        $this->innerExtractor->method('getPort')->willReturn('8080');
        $this->innerExtractor->method('getScheme')->willReturn('https');
        $this->innerExtractor->method('getCachePath')->with('en')->willReturn('/tmp/cache');
        $this->innerExtractor->method('getResources')->willReturn([]);

        $this->assertSame('/base', $this->extractor->getBaseUrl());
        $this->assertSame('en_', $this->extractor->getPrefix('en'));
        $this->assertSame('example.com', $this->extractor->getHost());
        $this->assertSame('8080', $this->extractor->getPort());
        $this->assertSame('https', $this->extractor->getScheme());
        $this->assertSame('/tmp/cache', $this->extractor->getCachePath('en'));
        $this->assertSame([], $this->extractor->getResources());
    }

    public function testIsRouteExposedDelegation(): void
    {
        $route = new Route('/admin/dashboard', [], [], ['expose' => true, 'role' => 'ROLE_ADMIN']);

        $this->innerExtractor->method('isRouteExposed')->with($route, 'admin_dashboard')->willReturn(true);
        $this->accessMap->method('getPatterns')->willReturn([null, null]);

        $this->authChecker->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);
        $this->assertTrue($this->extractor->isRouteExposed($route, 'admin_dashboard'));

        $this->authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->authChecker->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);
        $extractor2 = new RoleFilteredJsRoutesExtractor($this->innerExtractor, $this->authChecker, $this->accessMap);
        $this->assertFalse($extractor2->isRouteExposed($route, 'admin_dashboard'));
    }
}
