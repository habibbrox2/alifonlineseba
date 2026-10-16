<?php

declare(strict_types=1);

namespace App\Web\Site;

use App\Repository\ServiceRepository;
use App\Service\CategoryAccent;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class HomeAction
{
    private const POPULAR_LIMIT = 6;

    public function __construct(
        private WebViewRenderer $view,
        private ServiceRepository $services,
        private CategoryAccent $accents,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $categories = $this->services->allCategories();

        $accentByCategory = [];
        foreach ($categories as $category) {
            $accentByCategory[(int) $category['id']] = $this->accents->key($category);
        }

        // Tint each shelf card by its category, exactly like the services grid.
        $popular = $this->services->popularServices(self::POPULAR_LIMIT);
        foreach ($popular as $i => $service) {
            $popular[$i]['accent'] = $accentByCategory[(int) $service['category_id']]
                ?? CategoryAccent::DEFAULT_ACCENT;
        }

        return $this->view->render('site/home.twig', [
            'categories' => $categories,
            'popular' => $popular,
            // The stat strip quotes these, so they are counted live rather
            // than hard-coded: a stale "৩০০০+ সেবা" is worse than none.
            'stats' => [
                'services' => $this->services->countActiveServices(),
                'categories' => count($categories),
            ],
        ]);
    }
}
