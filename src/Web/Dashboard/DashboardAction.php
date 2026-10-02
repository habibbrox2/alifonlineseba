<?php

declare(strict_types=1);

namespace App\Web\Dashboard;

use App\Auth\Identity;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\SettingsRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\CategoryAccent;
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
        private UserRepository $users,
        private SettingsRepository $settings,
        private CategoryAccent $accents,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        $categories = $this->services->allCategories();
        $allServices = $this->services->servicesByCategory();
        $stats = $this->transactions->statsForUser($identity->id);
        // Recent searches for the dashboard panel. A small fixed list on purpose:
        // this is a shortcut back into a form, not a history page — /service-history
        // owns the full list.
        $searches = $this->transactions->recentSearches($identity->id, 6);

        // Attach category slug + accent to each service: the slug drives
        // client-side filtering, the accent tints each card with its category colour.
        $catMap = [];
        foreach ($categories as $cat) {
            $catMap[(int) $cat['id']] = ['slug' => $cat['slug'], 'accent' => $this->accents->key($cat)];
        }
        $allServices = array_map(static function (array $svc) use ($catMap): array {
            $meta = $catMap[(int) $svc['category_id']] ?? null;
            $svc['category_slug'] = $meta['slug'] ?? null;
            $svc['accent'] = $meta['accent'] ?? CategoryAccent::DEFAULT_ACCENT;
            return $svc;
        }, $allServices);

        $categories = array_map(function (array $cat): array {
            $cat['accent'] = $this->accents->key($cat);
            return $cat;
        }, $categories);

        return $this->view->render('site/dashboard/index.twig', [
            'categories' => $categories,
            'services' => $allServices,
            'stats' => [
                'total' => $stats['total'],
                'amount' => $stats['amount'],
                'completed' => $this->transactions->completedCount($identity->id),
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
