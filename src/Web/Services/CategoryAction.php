<?php

declare(strict_types=1);

namespace App\Web\Services;

use App\Auth\Identity;
use App\Repository\ServiceRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class CategoryAction
{
    public function __construct(
        private WebViewRenderer $view,
        private ServiceRepository $services,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $slug = $route->getArgument('slug');

        // /services — show all active services
        if ($slug === null) {
            $services = $this->services->servicesByCategory();
            return $this->view->render('site/services/category.twig', [
                'category' => [
                    'name' => 'সকল সার্ভিস',
                    'description' => 'TH Tools এর সকল ডেমো সার্ভিস একসাথে',
                    'icon' => 'layers',
                ],
                'services' => $services,
                'identity' => $identity,
            ]);
        }

        $category = $this->services->findCategoryBySlug($slug);

        if ($category === null) {
            return new \Nyholm\Psr7\Response(302, ['Location' => '/services']);
        }

        $services = $this->services->servicesByCategory((int) $category['id']);

        return $this->view->render('site/services/category.twig', [
            'category' => $category,
            'services' => $services,
            'identity' => $identity,
        ]);
    }
}
