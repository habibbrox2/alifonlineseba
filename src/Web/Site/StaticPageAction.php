<?php

declare(strict_types=1);

namespace App\Web\Site;

use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class StaticPageAction
{
    private const PAGES = ['about', 'privacy', 'terms'];

    public function __construct(private WebViewRenderer $view) {}

    public function __invoke(string $page): ResponseInterface
    {
        if (!in_array($page, self::PAGES, true)) {
            return new \Nyholm\Psr7\Response(404);
        }
        return $this->view->render("site/{$page}.twig");
    }
}
