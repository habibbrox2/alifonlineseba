<?php

declare(strict_types=1);

namespace App\Web\Services;

use App\Auth\Identity;
use App\Repository\ServiceRepository;
use App\Service\CategoryAccent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class CategoryAction
{
    public function __construct(
        private WebViewRenderer $view,
        private ServiceRepository $services,
        private CategoryAccent $accents,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $slug = $route->getArgument('slug');

        // /services — show all active services
        if ($slug === null) {
            $services = $this->withAccents($this->services->servicesByCategory());
            return $this->view->render('site/services/category.twig', [
                'category' => [
                    'name' => 'সকল সার্ভিস',
                    'description' => 'Alif Tools এর সকল ডেমো সার্ভিস একসাথে',
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

        $services = $this->withAccents($this->services->servicesByCategory((int) $category['id']));

        return $this->view->render('site/services/category.twig', [
            'category' => $category,
            'services' => $services,
            'identity' => $identity,
        ]);
    }

    /**
     * Stamp every service with its category's accent key, so a card can be
     * tinted per category even on the mixed /services listing.
     *
     * @param list<array<string, mixed>> $services
     * @return list<array<string, mixed>>
     */
    private function withAccents(array $services): array
    {
        $byId = [];
        foreach ($this->services->allCategories(false) as $cat) {
            $byId[(int) $cat['id']] = $this->accents->key($cat);
        }

        return array_map(static function (array $svc) use ($byId): array {
            $svc['accent'] = $byId[(int) $svc['category_id']] ?? CategoryAccent::DEFAULT_ACCENT;
            return $svc;
        }, $services);
    }
}
