<?php

declare(strict_types=1);

namespace App\Web\Dashboard;

use App\Auth\Identity;
use App\Repository\NotificationRepository;
use App\Repository\SettingsRepository;
use App\Repository\ServiceOrderRepository;
use App\Repository\UserRepository;
use App\Service\ServiceCatalog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class DashboardAction
{
    public function __construct(
        private WebViewRenderer $view,
        private ServiceCatalog $catalog,
        private ServiceOrderRepository $orders,
        private NotificationRepository $notifications,
        private UserRepository $users,
        private SettingsRepository $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        // The chips are server-rendered; the cards behind them are not. The
        // grid's rows moved to `GET /api/catalog` (see ServiceCatalogApiAction)
        // because inlining 3 135 services made this page 2.7 MB — so `services`
        // is no longer a template parameter at all.
        $categories = $this->catalog->categories();
        $stats = $this->orders->statsForUser($identity->id);
        // Recent searches for the dashboard panel. A small fixed list on purpose:
        // this is a shortcut back into a form, not a history page — /service-history
        // owns the full list.
        $searches = $this->orders->recentSearches($identity->id, 6);

        return $this->view->render('site/dashboard/index.twig', [
            'categories' => $categories,
            'stats' => [
                'total' => $stats['total'],
                'amount' => $stats['amount'],
                'completed' => $this->orders->completedCount($identity->id),
            ],
            'unread' => $this->notifications->unreadCount($identity->id),
            'searches' => $searches,
            'identity' => $identity,
            'freeSearches' => $this->users->freeSearches($identity->id),
            'notice' => $this->notice(),
        ]);
    }

    /** @return array{enabled: bool, title: string, body: string} */
    private function notice(): array
    {
        return [
            'enabled' => $this->settings->get('notice_enabled', '0') === '1',
            'title' => trim($this->settings->get('notice_title', 'জরুরি নোটিশ')),
            'body' => trim($this->settings->get('notice_body', '')),
        ];
    }
}
