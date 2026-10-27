<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Vagabit\FOSJsRoutingSecurityBundle\Command\WarmupJsRoutesCacheCommand;
use Vagabit\FOSJsRoutingSecurityBundle\Service\JsRoutesCacheWarmer;

class CacheWarmerIntegrationTest extends WebTestCase
{
    public function testWarmupPrepopulatesCacheForAnonymousAndRoles(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        /** @var JsRoutesCacheWarmer $warmer */
        $warmer = $container->get(JsRoutesCacheWarmer::class);

        /** @var CacheInterface $cache */
        $cache = $container->get('cache.app');

        // Warm up cache for Anonymous, ROLE_USER, and ROLE_ADMIN
        $results = $warmer->warmUpAll([
            ['ROLE_ANONYMOUS'],
            ['ROLE_USER'],
            ['ROLE_ADMIN'],
        ]);

        $this->assertCount(3, $results);

        $anonKey = $warmer->getCacheKey(['ROLE_ANONYMOUS']);
        $userKey = $warmer->getCacheKey(['ROLE_USER']);
        $adminKey = $warmer->getCacheKey(['ROLE_ADMIN']);

        $this->assertArrayHasKey($anonKey, $results);
        $this->assertArrayHasKey($userKey, $results);
        $this->assertArrayHasKey($adminKey, $results);

        // Verify Anonymous has public routes only
        $anonRoutes = (array) $results[$anonKey]['routes'];
        $this->assertArrayHasKey('public_home', $anonRoutes);
        $this->assertArrayNotHasKey('admin_dashboard', $anonRoutes);
        $this->assertArrayNotHasKey('account_profile', $anonRoutes);

        // Verify ROLE_ADMIN has admin routes
        $adminRoutes = (array) $results[$adminKey]['routes'];
        $this->assertArrayHasKey('public_home', $adminRoutes);
        $this->assertArrayHasKey('admin_dashboard', $adminRoutes);

        // Now test HTTP request for anonymous user hits pre-warmed cache directly
        $client->request('GET', '/vagabit-secure-js-routes.json');
        $this->assertResponseIsSuccessful();

        $responseContent = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('public_home', $responseContent['routes']);
        $this->assertArrayNotHasKey('admin_dashboard', $responseContent['routes']);
    }

    public function testWarmupCommandExecution(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        /** @var WarmupJsRoutesCacheCommand $command */
        $command = $container->get(WarmupJsRoutesCacheCommand::class);

        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $tester->execute(['--role' => ['ROLE_ADMIN']]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Successfully warmed up 1 role route combination(s)', $output);
    }
}
