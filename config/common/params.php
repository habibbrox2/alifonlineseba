<?php

declare(strict_types=1);

use App\Web\View\GoogleSignInViewInjection;
use App\Web\View\IdentityViewInjection;
use Yiisoft\Definitions\Reference;
use Yiisoft\View\Twig\TwigTemplateRenderer;
use Yiisoft\Yii\View\Renderer\CsrfViewInjection;

return [
    'application' => [
        'charset' => 'UTF-8',
        'locale' => 'bn',
        'name' => 'All Seba',
    ],

    'app' => [
        'name' => 'All Seba',
        'tagline' => 'ডিজিটাল সেবা হাব',
        'telegram' => 'https://t.me/aliftools',
        'support' => 'https://t.me/aliftools_support',
    ],

    'pagination' => [
        'pageSize' => 12,
        'maxPageLinks' => 7,
    ],

    'yiisoft/aliases' => [
        'aliases' => require __DIR__ . '/aliases.php',
    ],

    'yiisoft/view' => [
        'basePath' => '@views',
        'parameters' => [],
        'theme' => [
            'pathMap' => [],
            'basePath' => '',
            'baseUrl' => '',
        ],
        'renderers' => [
            'twig' => Reference::to(TwigTemplateRenderer::class),
        ],
        'fallbackExtension' => 'twig',
    ],

    'yiisoft/yii-view-renderer' => [
        'viewPath' => '@views',
        'layout' => null,
        'injections' => [
            Reference::to(CsrfViewInjection::class),
            Reference::to(IdentityViewInjection::class),
            Reference::to(\App\Web\View\SettingsViewInjection::class),
            Reference::to(\App\Web\View\PushViewInjection::class),
            // The drop zone's accept list and size ceiling, read from the class
            // that enforces them, so the widget cannot advertise different rules
            // than the server applies.
            Reference::to(\App\Web\View\ImageUploadViewInjection::class),
            // Firebase/Google Sign-In: the web SDK config (client id, API key,
            // auth domain, project id, app id) comes from the environment, and
            // the button is hidden entirely when GOOGLE_SIGNIN_WEB_CLIENT_ID is
            // not set — so this injection only has to produce values, the partial
            // guards on them.
            Reference::to(GoogleSignInViewInjection::class),
        ],
    ],
];
