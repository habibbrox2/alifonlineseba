<?php

declare(strict_types=1);

namespace App\Web\Site;

use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class HomeAction
{
    public function __construct(private WebViewRenderer $view) {}

    public function __invoke(): ResponseInterface
    {
        return $this->view->render('site/home.twig');
    }
}
