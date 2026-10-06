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
            // Templates are compiled into runtime/twig and, with debug off, Twig
            // would never recompile them after an edit — a changed view silently
            // kept rendering the old markup. auto_reload costs one stat per
            // render and picks edits up immediately.
            'auto_reload' => true,
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
