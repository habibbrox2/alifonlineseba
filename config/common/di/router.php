<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use Yiisoft\Config\Config;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Router\FastRoute\UrlMatcher;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\RouteCollector;
use Yiisoft\Router\UrlMatcherInterface;

/** @var Config $config */

return [
    RouteCollectionInterface::class => [
        'class' => RouteCollection::class,
        '__construct()' => [
            'collector' => DynamicReference::to(
                static fn() => (new RouteCollector())->addRoute(...$config->get('routes')),
            ),
        ],
    ],

    // Bound explicitly rather than left to autowiring, because the matcher
    // caches its compiled dispatch table in whatever CacheInterface it is
    // given — and it does so under one fixed key, with no check of whether the
    // routes have changed since. The consequence is silent: a route added or
    // given a new method keeps being served from the old table, so a POST to a
    // path that was just given one answers 405 with `Allow: GET` from a file
    // that nobody knows is holding the previous version of the app. Keying the
    // cache on the mtime of the route file makes an edit take effect on the
    // next request, which is the only thing anyone editing routes expects.
    UrlMatcherInterface::class => static function (
        RouteCollectionInterface $routes,
        CacheInterface $cache,
    ): UrlMatcherInterface {
        return new UrlMatcher($routes, $cache, [
            'cache_key' => 'routes-cache-' . (string) filemtime(__DIR__ . '/../routes.php'),
        ]);
    },
];
