<?php

declare(strict_types=1);

use App\Web\CsrfFailureHandler;
use App\Web\CsrfMiddleware;
use App\Web\FlashMiddleware;
use App\Web\ForwardedProtoMiddleware;
use App\Web\JsonBodyMiddleware;
use App\Web\MaintenanceMiddleware;
use App\Web\NoIndexMiddleware;
use App\Web\NotFound\NotFoundHandler;
use App\Web\SecurityHeadersMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Yiisoft\Csrf\CsrfTokenInterface;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\Input\Http\HydratorAttributeParametersResolver;
use Yiisoft\Input\Http\RequestInputParametersResolver;
use Yiisoft\Middleware\Dispatcher\CompositeParametersResolver;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Middleware\Dispatcher\ParametersResolverInterface;
use Yiisoft\RequestProvider\RequestCatcherMiddleware;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Session\SessionMiddleware;
use Yiisoft\Yii\Http\Application;

/** @var array $params */

return [
    Application::class => [
        '__construct()' => [
            'dispatcher' => DynamicReference::to([
                'class' => MiddlewareDispatcher::class,
                'withMiddlewares()' => [
                    [
                        ErrorCatcher::class,
                        SecurityHeadersMiddleware::class,
                        // Grouped with the other response headers, inside
                        // ErrorCatcher. A beta copy reached through a
                        // non-canonical host must be marked noindex on every
                        // page it serves.
                        NoIndexMiddleware::class,
                        // Rewrites the scheme first, then the request is caught so that
                        // SecureCookieSession sees the proxy-aware scheme.
                        ForwardedProtoMiddleware::class,
                        RequestCatcherMiddleware::class,
                        SessionMiddleware::class,
                        FlashMiddleware::class,
                        // Wraps the stock CsrfTokenMiddleware: same token
                        // check everywhere except /__/auth/*, which Firebase's
                        // own sign-in popup posts to and cannot be handed a
                        // token of ours. See App\Web\CsrfMiddleware.
                        CsrfMiddleware::class,
                        // After CSRF so a request with no valid token is turned
                        // away before we spend anything reading its body.
                        JsonBodyMiddleware::class,
                        // Last before routing: everything the view injections
                        // reach for (session, flash, CSRF, the captured
                        // request) is already in place, so the maintenance page
                        // renders like any other page — while nothing behind
                        // the router is ever built for a request the owner has
                        // closed. See App\Web\MaintenanceMiddleware for why the
                        // admin paths stay open.
                        MaintenanceMiddleware::class,
                        Router::class,
                    ],
                ],
            ]),
            'fallbackHandler' => Reference::to(NotFoundHandler::class),
        ],
    ],

    // The stock CsrfTokenMiddleware answers a failed check with a status and an
    // empty body. That is a dead end on a form — the login POST that arrives
    // with a token minted for a session since replaced shows the visitor a blank
    // page — so it gets a handler that explains the failure and offers a reload.
    // The check itself, and the 422 it refuses with, are unchanged.
    CsrfTokenMiddleware::class => [
        '__construct()' => [
            Reference::to(ResponseFactoryInterface::class),
            Reference::to(CsrfTokenInterface::class),
            Reference::to(CsrfFailureHandler::class),
        ],
    ],

    ParametersResolverInterface::class => [
        'class' => CompositeParametersResolver::class,
        '__construct()' => [
            Reference::to(HydratorAttributeParametersResolver::class),
            Reference::to(RequestInputParametersResolver::class),
        ],
    ],
];
