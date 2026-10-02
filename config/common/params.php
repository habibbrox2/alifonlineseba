<?php

declare(strict_types=1);

use App\Web\View\IdentityViewInjection;
use Yiisoft\Definitions\Reference;
use Yiisoft\View\Twig\TwigTemplateRenderer;
use Yiisoft\Yii\View\Renderer\CsrfViewInjection;

return [
    'application' => [
        'charset' => 'UTF-8',
        'locale' => 'bn',
        'name' => 'Alif Tools',
    ],

    'app' => [
        'name' => 'Alif Tools',
        'tagline' => 'ডিজিটাল সেবা হাব',
        'telegram' => 'https://t.me/example_demo_channel',
        'support' => 'https://t.me/example_demo_support',
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
        ],
    ],
];
