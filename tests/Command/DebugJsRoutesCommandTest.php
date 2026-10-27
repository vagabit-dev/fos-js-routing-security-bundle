<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Tests\Command;

use FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\AccessMapInterface;
use Vagabit\FOSJsRoutingSecurityBundle\Command\DebugJsRoutesCommand;
use Vagabit\FOSJsRoutingSecurityBundle\Service\RoleFilteredJsRoutesExtractor;

class DebugJsRoutesCommandTest extends TestCase
{
    private RouterInterface $router;
    private ExposedRoutesExtractorInterface $extractor;
    private AccessMapInterface $accessMap;
    private TokenStorageInterface $tokenStorage;
    private AuthorizationCheckerInterface $authChecker;
    private RouteCollection $routeCollection;

    protected function setUp(): void
    {
        $this->router = $this->createMock(RouterInterface::class);
        $this->extractor = $this->createMock(ExposedRoutesExtractorInterface::class);
        $this->accessMap = $this->createMock(AccessMapInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->authChecker = $this->createMock(AuthorizationCheckerInterface::class);

        $this->routeCollection = new RouteCollection();
        $this->router->method('getRouteCollection')->willReturn($this->routeCollection);
    }

    public function testExecuteDefaultDumpsExposedAndNonExposedRoutes(): void
    {
        // 1. Public exposed route
        $publicRoute = new Route('/public/home', [], [], ['expose' => true]);
        $this->routeCollection->add('public_home', $publicRoute);

        // 2. Admin exposed route with security.yaml access_control
        $adminRoute = new Route('/admin/dashboard', [], [], ['expose' => true], '', [], ['GET']);
        $this->routeCollection->add('admin_dashboard', $adminRoute);

        // 3. Manager exposed route with custom role option
        $managerRoute = new Route('/manager/reports', [], [], ['expose' => true, 'role' => 'ROLE_MANAGER']);
        $this->routeCollection->add('manager_reports', $managerRoute);

        // 4. Non-exposed internal route
        $internalRoute = new Route('/internal/health', [], [], []);
        $this->routeCollection->add('internal_health', $internalRoute);

        $this->extractor->method('isRouteExposed')
            ->willReturnCallback(function (Route $route, string $name) {
                return in_array($name, ['public_home', 'admin_dashboard', 'manager_reports'], true);
            });

        $this->accessMap->method('getPatterns')
            ->willReturnCallback(function (Request $request) {
                if (str_starts_with($request->getPathInfo(), '/admin')) {
                    return [['ROLE_ADMIN'], null];
                }

                return [null, null];
            });

        $command = new DebugJsRoutesCommand(
            $this->router,
            $this->extractor,
            $this->accessMap,
            $this->tokenStorage,
            $this->authChecker
        );

        $application = new Application();
        $application->add($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        // Check header and sections
        $this->assertStringContainsString('FOSJsRouting Security - Route Role Exposure Debugger', $output);
        $this->assertStringContainsString('Exposed Routes (3)', $output);
        $this->assertStringContainsString('Non-Exposed Routes (1)', $output);

        // Check exposed routes
        $this->assertStringContainsString('public_home', $output);
        $this->assertStringContainsString('PUBLIC (All roles / Anonymous)', $output);

        $this->assertStringContainsString('admin_dashboard', $output);
        $this->assertStringContainsString('access_control: [ROLE_ADMIN]', $output);

        $this->assertStringContainsString('manager_reports', $output);
        $this->assertStringContainsString('Route Option: [ROLE_MANAGER]', $output);

        // Check non-exposed routes
        $this->assertStringContainsString('internal_health', $output);

        // Check summary
        $this->assertStringContainsString('Total routes: 4 (Exposed: 3, Non-Exposed: 1)', $output);
    }

    public function testExecuteWithSingleRoleOption(): void
    {
        $publicRoute = new Route('/public/home', [], [], ['expose' => true]);
        $this->routeCollection->add('public_home', $publicRoute);

        $adminRoute = new Route('/admin/dashboard', [], [], ['expose' => true]);
        $this->routeCollection->add('admin_dashboard', $adminRoute);

        $managerRoute = new Route('/manager/reports', [], [], ['expose' => true, 'role' => 'ROLE_MANAGER']);
        $this->routeCollection->add('manager_reports', $managerRoute);

        $this->extractor->method('isRouteExposed')->willReturn(true);

        $this->accessMap->method('getPatterns')
            ->willReturnCallback(function (Request $request) {
                if (str_starts_with($request->getPathInfo(), '/admin')) {
                    return [['ROLE_ADMIN'], null];
                }

                return [null, null];
            });

        // Use RoleFilteredJsRoutesExtractor as extractor
        $roleFilteredExtractor = new RoleFilteredJsRoutesExtractor(
            $this->extractor,
            $this->authChecker,
            $this->accessMap
        );

        $this->authChecker->method('isGranted')
            ->willReturnCallback(function (string $attribute) {
                return 'ROLE_ADMIN' === $attribute;
            });

        $command = new DebugJsRoutesCommand(
            $this->router,
            $roleFilteredExtractor,
            $this->accessMap,
            $this->tokenStorage,
            $this->authChecker
        );

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--role' => ['ROLE_ADMIN']]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Exposed Routes for Role(s): ROLE_ADMIN', $output);
        $this->assertStringContainsString('Matching Exposed Routes (2)', $output);
        $this->assertStringContainsString('public_home', $output);
        $this->assertStringContainsString('admin_dashboard', $output);
        $this->assertStringNotContainsString('manager_reports', $output);
    }

    public function testExecuteWithCommaSeparatedRoles(): void
    {
        $publicRoute = new Route('/public/home', [], [], ['expose' => true]);
        $this->routeCollection->add('public_home', $publicRoute);

        $adminRoute = new Route('/admin/dashboard', [], [], ['expose' => true]);
        $this->routeCollection->add('admin_dashboard', $adminRoute);

        $managerRoute = new Route('/manager/reports', [], [], ['expose' => true, 'role' => 'ROLE_MANAGER']);
        $this->routeCollection->add('manager_reports', $managerRoute);

        $this->extractor->method('isRouteExposed')->willReturn(true);

        $this->accessMap->method('getPatterns')
            ->willReturnCallback(function (Request $request) {
                if (str_starts_with($request->getPathInfo(), '/admin')) {
                    return [['ROLE_ADMIN'], null];
                }

                return [null, null];
            });

        $roleFilteredExtractor = new RoleFilteredJsRoutesExtractor(
            $this->extractor,
            $this->authChecker,
            $this->accessMap
        );

        $this->authChecker->method('isGranted')
            ->willReturnCallback(function (string $attribute) {
                return in_array($attribute, ['ROLE_ADMIN', 'ROLE_MANAGER'], true);
            });

        $command = new DebugJsRoutesCommand(
            $this->router,
            $roleFilteredExtractor,
            $this->accessMap,
            $this->tokenStorage,
            $this->authChecker
        );

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--role' => ['ROLE_ADMIN,ROLE_MANAGER']]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Exposed Routes for Role(s): ROLE_ADMIN, ROLE_MANAGER', $output);
        $this->assertStringContainsString('Matching Exposed Routes (3)', $output);
        $this->assertStringContainsString('public_home', $output);
        $this->assertStringContainsString('admin_dashboard', $output);
        $this->assertStringContainsString('manager_reports', $output);
    }

    public function testExecuteWithNoMatchingRoutes(): void
    {
        $adminRoute = new Route('/admin/dashboard', [], [], ['expose' => true]);
        $this->routeCollection->add('admin_dashboard', $adminRoute);

        $this->extractor->method('isRouteExposed')->willReturn(true);

        $this->accessMap->method('getPatterns')
            ->willReturn([['ROLE_ADMIN'], null]);

        $roleFilteredExtractor = new RoleFilteredJsRoutesExtractor(
            $this->extractor,
            $this->authChecker,
            $this->accessMap
        );

        $this->authChecker->method('isGranted')->willReturn(false);

        $command = new DebugJsRoutesCommand(
            $this->router,
            $roleFilteredExtractor,
            $this->accessMap,
            $this->tokenStorage,
            $this->authChecker
        );

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--role' => ['ROLE_GUEST']]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('No exposed routes found that are accessible by role(s): ROLE_GUEST', $output);
    }

    public function testExecuteWithoutTokenStorageUsesFallback(): void
    {
        $publicRoute = new Route('/public/home', [], [], ['expose' => true]);
        $this->routeCollection->add('public_home', $publicRoute);

        $adminRoute = new Route('/admin/dashboard', [], [], ['expose' => true]);
        $this->routeCollection->add('admin_dashboard', $adminRoute);

        $managerRoute = new Route('/manager/reports', [], [], ['expose' => true, 'role' => 'ROLE_MANAGER']);
        $this->routeCollection->add('manager_reports', $managerRoute);

        $this->extractor->method('isRouteExposed')->willReturn(true);

        $this->accessMap->method('getPatterns')
            ->willReturnCallback(function (Request $request) {
                if (str_starts_with($request->getPathInfo(), '/admin')) {
                    return [['ROLE_ADMIN'], null];
                }

                return [null, null];
            });

        $command = new DebugJsRoutesCommand(
            $this->router,
            $this->extractor,
            $this->accessMap,
            null,
            null
        );

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--role' => ['ROLE_MANAGER']]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Matching Exposed Routes (2)', $output);
        $this->assertStringContainsString('public_home', $output);
        $this->assertStringContainsString('manager_reports', $output);
        $this->assertStringNotContainsString('admin_dashboard', $output);
    }
}
