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
}
