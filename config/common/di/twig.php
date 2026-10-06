<?php

declare(strict_types=1);

use App\Twig\BarcodeExtension;
use App\Twig\TwigExtension;
use Yiisoft\Aliases\Aliases;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Yiisoft\View\Twig\TwigTemplateRenderer;

/** @var array $params */

return [
    LoaderInterface::class => static fn (Aliases $aliases): FilesystemLoader =>
        new FilesystemLoader($aliases->get('@views')),

    TwigEnvironment::class => static function (): TwigEnvironment {
        $templates = dirname(__DIR__, 3) . '/resources/views';
        $cachePath = dirname(__DIR__, 3) . '/runtime/twig';

        $environment = new TwigEnvironment(new FilesystemLoader($templates), [
            'cache' => $cachePath,
            'autoescape' => 'html',
            'debug' => \App\Env::isDev(),
            'strict_variables' => false,
        ]);
        $environment->addExtension(new TwigExtension());
        $environment->addExtension(new BarcodeExtension());

        return $environment;
    },

    TwigTemplateRenderer::class => static fn (TwigEnvironment $twig): TwigTemplateRenderer =>
        new TwigTemplateRenderer($twig),
];
