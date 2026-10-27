<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vagabit\FOSJsRoutingSecurityBundle\Kernel;

class DebugJsRoutesCommandIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => true]);
    }

    public function testDefaultExecutionDumpsExposedAndNonExposedRoutes(): void
    {
        $kernel = self::$kernel;
        $application = new Application($kernel);

        $command = $application->find('vagabit:js-routing:debug');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        // Must separate exposed and non-exposed routes
        $this->assertStringContainsString('FOSJsRouting Security - Route Role Exposure Debugger', $output);
        $this->assertStringContainsString('Exposed Routes', $output);
        $this->assertStringContainsString('Non-Exposed Routes', $output);

        // Exposed test routes
        $this->assertStringContainsString('public_home', $output);
        $this->assertStringContainsString('PUBLIC (All roles / Anonymous)', $output);

        $this->assertStringContainsString('admin_dashboard', $output);
        $this->assertStringContainsString('access_control: [ROLE_ADMIN]', $output);

        $this->assertStringContainsString('account_profile', $output);
        $this->assertStringContainsString('access_control: [ROLE_USER]', $output);

        $this->assertStringContainsString('manager_reports', $output);
        $this->assertStringContainsString('Route Option: [ROLE_MANAGER]', $output);

        // Non-exposed route from FOSJsRouting itself
        $this->assertStringContainsString('fos_js_routing_js', $output);
    }

    public function testExecuteFilteredByAdminRole(): void
    {
        $kernel = self::$kernel;
        $application = new Application($kernel);

        $command = $application->find('vagabit:js-routing:debug');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--role' => ['ROLE_ADMIN']]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Exposed Routes for Role(s): ROLE_ADMIN', $output);
        $this->assertStringContainsString('public_home', $output);
        $this->assertStringContainsString('admin_dashboard', $output);

        // Should NOT show manager or user exclusive routes
        $this->assertStringNotContainsString('manager_reports', $output);
        $this->assertStringNotContainsString('account_profile', $output);
    }

    public function testExecuteFilteredByMultipleRolesCommaSeparated(): void
    {
        $kernel = self::$kernel;
        $application = new Application($kernel);

        $command = $application->find('vagabit:js-routing:debug');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--role' => ['ROLE_ADMIN,ROLE_USER']]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Exposed Routes for Role(s): ROLE_ADMIN, ROLE_USER', $output);
        $this->assertStringContainsString('public_home', $output);
        $this->assertStringContainsString('admin_dashboard', $output);
        $this->assertStringContainsString('account_profile', $output);

        // manager_reports is not accessible by ROLE_ADMIN or ROLE_USER
        $this->assertStringNotContainsString('manager_reports', $output);
    }

    public function testInspectRouteByName(): void
    {
        $kernel = self::$kernel;
        $application = new Application($kernel);

        $command = $application->find('vagabit:js-routing:debug');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--route' => 'admin_dashboard']);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Route Details: admin_dashboard', $output);
        $this->assertStringContainsString('admin_dashboard', $output);
        $this->assertStringContainsString('/admin/dashboard', $output);
        $this->assertStringContainsString('ANY', $output);
        $this->assertStringContainsString('Yes', $output);
        $this->assertStringContainsString('access_control: [ROLE_ADMIN]', $output);
    }

    public function testInspectRouteByPath(): void
    {
        $kernel = self::$kernel;
        $application = new Application($kernel);

        $command = $application->find('vagabit:js-routing:debug');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--route' => '/public/home']);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Route Details: public_home', $output);
        $this->assertStringContainsString('/public/home', $output);
        $this->assertStringContainsString('PUBLIC (All roles / Anonymous)', $output);
    }

    public function testInspectRouteWithRoleFilter(): void
    {
        $kernel = self::$kernel;
        $application = new Application($kernel);

        $command = $application->find('vagabit:js-routing:debug');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--route' => 'admin_dashboard',
            '--role' => ['ROLE_ADMIN'],
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Route Details: admin_dashboard', $output);
        $this->assertStringContainsString('Accessible for Role(s) [ROLE_ADMIN]', $output);
        $this->assertStringContainsString('Yes', $output);
    }

    public function testInspectRouteNotFound(): void
    {
        $kernel = self::$kernel;
        $application = new Application($kernel);

        $command = $application->find('vagabit:js-routing:debug');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--route' => 'unknown_route_xyz']);

        $this->assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Route "unknown_route_xyz" not found (checked by name and path).', $output);
    }
}
