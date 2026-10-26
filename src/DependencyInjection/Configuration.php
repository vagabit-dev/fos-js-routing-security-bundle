<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('fos_js_routing_security');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->variableNode('cache_ttl')
                    ->defaultValue(3600)
                    ->info('Cache TTL in seconds. Set to null or "never" for entries that never expire, or 0 for immediate expiration.')
                    ->validate()
                        ->always(function ($v) {
                            if ('never' === $v || null === $v) {
                                return null;
                            }
                            if (is_numeric($v)) {
                                return (int) $v;
                            }

                            return $v;
                        })
                    ->end()
                ->end()
                ->scalarNode('cache_prefix')
                    ->defaultValue('js_routes_')
                    ->info('Prefix prepended to role-hashed cache keys.')
                ->end()
                ->scalarNode('cache_tag')
                    ->defaultValue('fos_js_routing_security')
                    ->info('Cache tag applied to cached route items for selective tag-based invalidation.')
                ->end()
                ->arrayNode('warmup_roles')
                    ->defaultValue([['ROLE_ANONYMOUS']])
                    ->info('List of role combinations to warm up in cache during cache:warmup (e.g. [["ROLE_ANONYMOUS"], ["ROLE_USER"], ["ROLE_ADMIN"]]).')
                    ->beforeNormalization()
                        ->always(function ($v) {
                            if (!is_array($v)) {
                                return $v;
                            }
                            $normalized = [];
                            foreach ($v as $item) {
                                if (is_string($item)) {
                                    $normalized[] = [$item];
                                } elseif (is_array($item)) {
                                    $normalized[] = $item;
                                }
                            }

                            return $normalized;
                        })
                    ->end()
                    ->prototype('array')
                        ->prototype('scalar')->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
