<?php

declare(strict_types=1);

namespace App\Web\Dashboard;

use App\Auth\Identity;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\TransactionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class DashboardAction
{
    public function __construct(
        private WebViewRenderer $view,
        private ServiceRepository $services,
        private TransactionRepository $transactions,
        private NotificationRepository $notifications,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        $categories = $this->services->allCategories();
        $allServices = $this->services->servicesByCategory();
        $stats = $this->transactions->statsForUser($identity->id);

        // Attach category slug to each service for client-side filtering
        $catMap = [];
        foreach ($categories as $cat) {
            $catMap[(int) $cat['id']] = $cat['slug'];
        }
        $allServices = array_map(static function (array $svc) use ($catMap): array {
            $svc['category_slug'] = $catMap[(int) $svc['category_id']] ?? null;
            return $svc;
        }, $allServices);

        return $this->view->render('site/dashboard/index.twig', [
            'categories' => $categories,
            'services' => $allServices,
            'stats' => $stats,
            'unread' => $this->notifications->unreadCount($identity->id),
            'identity' => $identity,
        ]);
    }
}
