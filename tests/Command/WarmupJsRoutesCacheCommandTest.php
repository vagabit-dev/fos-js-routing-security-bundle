<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\WarmupJsRoutesCacheCommand;
use App\Service\JsRoutesCacheWarmer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class WarmupJsRoutesCacheCommandTest extends TestCase
{
    public function testExecuteWithDefaultRoles(): void
    {
        $cacheWarmer = $this->createMock(JsRoutesCacheWarmer::class);
        $cacheWarmer->expects($this->once())
            ->method('warmUpAll')
            ->with(null)
            ->willReturn([
                'js_routes_'.md5('ROLE_ANONYMOUS') => [
                    'routes' => ['public_home' => []],
                ],
            ]);

        $command = new WarmupJsRoutesCacheCommand($cacheWarmer);
        $application = new Application();
        $application->add($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Warming up FOSJsRouting Security Role-Based Route Caches', $output);
        $this->assertStringContainsString('Successfully warmed up 1 role route combination(s).', $output);
    }

    public function testExecuteWithCustomRoleOptions(): void
    {
        $cacheWarmer = $this->createMock(JsRoutesCacheWarmer::class);
        $cacheWarmer->expects($this->once())
            ->method('warmUpAll')
            ->with([['ROLE_ADMIN', 'ROLE_USER']])
            ->willReturn([
                'js_routes_'.md5('ROLE_ADMIN_ROLE_USER') => [
                    'routes' => ['admin_dashboard' => [], 'user_profile' => []],
                ],
            ]);

        $command = new WarmupJsRoutesCacheCommand($cacheWarmer);
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--role' => ['ROLE_ADMIN', 'ROLE_USER'],
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Successfully warmed up 1 role route combination(s).', $output);
        $this->assertStringContainsString('2', $output); // 2 routes
    }
}
