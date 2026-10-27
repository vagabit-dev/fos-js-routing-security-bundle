<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vagabit\FOSJsRoutingSecurityBundle\DependencyInjection\FOSJsRoutingSecurityExtension;

class FOSJsRoutingSecurityExtensionTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $container = new ContainerBuilder();
        $extension = new FOSJsRoutingSecurityExtension();

        $extension->load([], $container);

        $this->assertSame(3600, $container->getParameter('fos_js_routing_security.cache_ttl'));
        $this->assertSame('js_routes_', $container->getParameter('fos_js_routing_security.cache_prefix'));
        $this->assertSame('fos_js_routing_security', $container->getParameter('fos_js_routing_security.cache_tag'));
        $this->assertSame([['ROLE_ANONYMOUS']], $container->getParameter('fos_js_routing_security.warmup_roles'));
        $this->assertSame([
            'private' => true,
            'no_store' => true,
            'max_age' => 0,
            'must_revalidate' => false,
            'etag' => false,
        ], $container->getParameter('fos_js_routing_security.http_cache'));
    }

    public function testCustomConfiguration(): void
    {
        $container = new ContainerBuilder();
        $extension = new FOSJsRoutingSecurityExtension();

        $config = [
            'fos_js_routing_security' => [
                'cache_ttl' => 7200,
                'cache_prefix' => 'custom_routes_',
                'cache_tag' => 'my_routes_tag',
                'warmup_roles' => [
                    'ROLE_ANONYMOUS',
                    'ROLE_USER',
                    ['ROLE_ADMIN', 'ROLE_USER'],
                ],
                'http_cache' => [
                    'private' => true,
                    'no_store' => false,
                    'max_age' => 300,
                    'must_revalidate' => true,
                    'etag' => true,
                ],
            ],
        ];

        $extension->load($config, $container);

        $this->assertSame(7200, $container->getParameter('fos_js_routing_security.cache_ttl'));
        $this->assertSame('custom_routes_', $container->getParameter('fos_js_routing_security.cache_prefix'));
        $this->assertSame('my_routes_tag', $container->getParameter('fos_js_routing_security.cache_tag'));
        $this->assertSame([
            ['ROLE_ANONYMOUS'],
            ['ROLE_USER'],
            ['ROLE_ADMIN', 'ROLE_USER'],
        ], $container->getParameter('fos_js_routing_security.warmup_roles'));
        $this->assertSame([
            'private' => true,
            'no_store' => false,
            'max_age' => 300,
            'must_revalidate' => true,
            'etag' => true,
        ], $container->getParameter('fos_js_routing_security.http_cache'));
    }

    public function testNeverExpireConfigurationWithString(): void
    {
        $container = new ContainerBuilder();
        $extension = new FOSJsRoutingSecurityExtension();

        $config = [
            'fos_js_routing_security' => [
                'cache_ttl' => 'never',
            ],
        ];

        $extension->load($config, $container);

        $this->assertNull($container->getParameter('fos_js_routing_security.cache_ttl'));
    }

    public function testZeroTtlConfiguration(): void
    {
        $container = new ContainerBuilder();
        $extension = new FOSJsRoutingSecurityExtension();

        $config = [
            'fos_js_routing_security' => [
                'cache_ttl' => 0,
            ],
        ];

        $extension->load($config, $container);

        $this->assertSame(0, $container->getParameter('fos_js_routing_security.cache_ttl'));
    }

    public function testNeverExpireConfigurationWithNull(): void
    {
        $container = new ContainerBuilder();
        $extension = new FOSJsRoutingSecurityExtension();

        $config = [
            'fos_js_routing_security' => [
                'cache_ttl' => null,
            ],
        ];

        $extension->load($config, $container);

        $this->assertNull($container->getParameter('fos_js_routing_security.cache_ttl'));
    }

    public function testServiceDefinitionsAndDecorationAreRegistered(): void
    {
        $container = new ContainerBuilder();
        $extension = new FOSJsRoutingSecurityExtension();

        $extension->load([], $container);

        // Verify ExposedRoutesExtractorInterface alias
        $this->assertTrue($container->hasAlias(\FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface::class));
        $this->assertSame('fos_js_routing.extractor', (string) $container->getAlias(\FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface::class));

        // Verify RoleFilteredJsRoutesExtractor decoration
        $this->assertTrue($container->hasDefinition(\Vagabit\FOSJsRoutingSecurityBundle\Service\RoleFilteredJsRoutesExtractor::class));
        $decoratorDef = $container->getDefinition(\Vagabit\FOSJsRoutingSecurityBundle\Service\RoleFilteredJsRoutesExtractor::class);
        $this->assertSame(['fos_js_routing.extractor', null, 0], $decoratorDef->getDecoratedService());

        // Verify JsRoutesCacheWarmer registration and tag
        $this->assertTrue($container->hasDefinition(\Vagabit\FOSJsRoutingSecurityBundle\Service\JsRoutesCacheWarmer::class));
        $warmerDef = $container->getDefinition(\Vagabit\FOSJsRoutingSecurityBundle\Service\JsRoutesCacheWarmer::class);
        $this->assertTrue($warmerDef->hasTag('kernel.cache_warmer'));

        // Verify WarmupJsRoutesCacheCommand registration and tag
        $this->assertTrue($container->hasDefinition(\Vagabit\FOSJsRoutingSecurityBundle\Command\WarmupJsRoutesCacheCommand::class));
        $commandDef = $container->getDefinition(\Vagabit\FOSJsRoutingSecurityBundle\Command\WarmupJsRoutesCacheCommand::class);
        $this->assertTrue($commandDef->hasTag('console.command'));

        // Verify JsRoutingController registration and tag
        $this->assertTrue($container->hasDefinition(\Vagabit\FOSJsRoutingSecurityBundle\Controller\JsRoutingController::class));
        $controllerDef = $container->getDefinition(\Vagabit\FOSJsRoutingSecurityBundle\Controller\JsRoutingController::class);
        $this->assertTrue($controllerDef->hasTag('controller.service_arguments'));
    }
}
