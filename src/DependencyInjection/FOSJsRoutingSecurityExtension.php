<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\DependencyInjection;

use FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vagabit\FOSJsRoutingSecurityBundle\Command\WarmupJsRoutesCacheCommand;
use Vagabit\FOSJsRoutingSecurityBundle\Controller\JsRoutingController;
use Vagabit\FOSJsRoutingSecurityBundle\Service\JsRoutesCacheWarmer;
use Vagabit\FOSJsRoutingSecurityBundle\Service\RoleFilteredJsRoutesExtractor;

class FOSJsRoutingSecurityExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $cacheTtl = array_key_exists('cache_ttl', $config) ? $config['cache_ttl'] : 3600;
        $cachePrefix = $config['cache_prefix'] ?? 'js_routes_';
        $cacheTag = $config['cache_tag'] ?? 'fos_js_routing_security';
        $warmupRoles = $config['warmup_roles'] ?? [['ROLE_ANONYMOUS']];
        $httpCache = $config['http_cache'] ?? [
            'private' => true,
            'no_store' => true,
            'max_age' => 0,
            'must_revalidate' => false,
            'etag' => false,
        ];

        $container->setParameter('fos_js_routing_security.cache_ttl', $cacheTtl);
        $container->setParameter('fos_js_routing_security.cache_prefix', $cachePrefix);
        $container->setParameter('fos_js_routing_security.cache_tag', $cacheTag);
        $container->setParameter('fos_js_routing_security.warmup_roles', $warmupRoles);
        $container->setParameter('fos_js_routing_security.http_cache', $httpCache);

        // Alias ExposedRoutesExtractorInterface to fos_js_routing.extractor
        $container->setAlias(ExposedRoutesExtractorInterface::class, 'fos_js_routing.extractor')
            ->setPublic(true);

        // Automatically register and decorate fos_js_routing.extractor
        $container->register(RoleFilteredJsRoutesExtractor::class, RoleFilteredJsRoutesExtractor::class)
            ->setDecoratedService('fos_js_routing.extractor')
            ->setArguments([
                new Reference(RoleFilteredJsRoutesExtractor::class.'.inner'),
                new Reference('security.authorization_checker'),
                new Reference('security.access_map'),
            ])
            ->setPublic(true);

        // Register JsRoutesCacheWarmer as kernel.cache_warmer
        $container->register(JsRoutesCacheWarmer::class, JsRoutesCacheWarmer::class)
            ->setArguments([
                new Reference('fos_js_routing.extractor'),
                new Reference('cache.app'),
                new Reference('security.token_storage', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
                '%fos_js_routing_security.warmup_roles%',
                '%fos_js_routing_security.cache_ttl%',
                '%fos_js_routing_security.cache_prefix%',
                '%fos_js_routing_security.cache_tag%',
            ])
            ->addTag('kernel.cache_warmer', ['priority' => 0])
            ->setPublic(true);

        // Register WarmupJsRoutesCacheCommand
        $container->register(WarmupJsRoutesCacheCommand::class, WarmupJsRoutesCacheCommand::class)
            ->setArguments([
                new Reference(JsRoutesCacheWarmer::class),
            ])
            ->addTag('console.command')
            ->setPublic(true);

        // Register JsRoutingController
        $container->register(JsRoutingController::class, JsRoutingController::class)
            ->setArguments([
                '%fos_js_routing_security.cache_ttl%',
                '%fos_js_routing_security.cache_prefix%',
                '%fos_js_routing_security.cache_tag%',
                new Reference('security.token_storage', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
                '%fos_js_routing_security.http_cache%',
            ])
            ->addTag('controller.service_arguments')
            ->setPublic(true);
    }

    public function getAlias(): string
    {
        return 'fos_js_routing_security';
    }
}
