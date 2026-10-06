<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Auth\Identity;
use App\Repository\ServiceOrderRepository;
use App\Repository\ServiceRepository;
use App\Service\RequestRowPresenter;
use App\Service\ServiceManager;
use App\Service\StatusPresenter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET /service-history — every service request the signed-in user made, with
 * the actions available for its current status.
 */
final readonly class ServiceHistoryAction
{
    private const PER_PAGE = 15;

    public function __construct(
        private WebViewRenderer $view,
        private ServiceOrderRepository $orders,
        private ServiceRepository $services,
        private RequestRowPresenter $presenter,
        private ServiceManager $manager,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $page = max(1, (int) ($route->getArgument('page', '1')));

        $status = (string) ($request->getQueryParams()['status'] ?? '');
        if (!StatusPresenter::isRequestStatus($status)) {
            $status = '';
        }

        // Order-type chip (ফুল NID / লোকেশন / …) — a service-category slug.
        $category = (string) ($request->getQueryParams()['type'] ?? '');

        if ($category !== '') {
            $data = $this->orders->forUserByCategory($identity->id, $page, self::PER_PAGE, $category);
            // An unknown slug returns 0 rows; that is the correct empty state,
            // same as a status filter that matches nothing.
        } else {
            $data = $this->orders->forUser($identity->id, $page, self::PER_PAGE, $status);
        }

        // An auto-generating order is finished by the act of looking at it, once its
        // delay has passed: this page is where the customer is already waiting,
        // and it is the same read the poller performs. Orders are re-read
        // afterwards so the very load that builds the card also shows it.
        $this->manager->settleAutoOrders($data['rows']);
        $data = $this->reRead($category, $page, $status, $identity->id);

        $rows = $this->present($data['rows']);

        return $this->view->render('site/account/service-history.twig', [
            'requests' => $rows,
            // The ids the poller asks about. Seeded server-side rather than read
            // back out of the DOM so the client never has to scrape the table to
            // find out what it is supposed to be watching.
            'watchIds' => array_column($rows, 'id'),
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'status' => $status,
            'category' => $category,
            'categories' => $this->services->allCategories(),
            'categoryCounts' => $this->orders->categoryCounts($identity->id),
            'counts' => $this->counts($identity->id),
            'identity' => $identity,
        ]);
    }

    /**
     * Attach the presentation data each row needs so the template stays dumb and
     * the poller reuses the exact same payload when a row changes underneath it.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function present(array $rows): array
    {
        $presented = [];
        foreach ($rows as $row) {
            $presented[] = $this->presenter->present($row);
        }

        return $presented;
    }

    /**
     * Run the same query again, with the same filters.
     *
     * Written out rather than reusing `$data` because settling may have changed
     * rows this page is about to render, and a history page that loaded for the
     * one moment *before* its own download button existed is exactly what a
     * customer would describe as "it says done but there is no file".
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    private function reRead(string $category, int $page, string $status, int $userId): array
    {
        return $category !== ''
            ? $this->orders->forUserByCategory($userId, $page, self::PER_PAGE, $category)
            : $this->orders->forUser($userId, $page, self::PER_PAGE, $status);
    }

    /**
     * Row count per status, for the filter chips. Every known status is present
     * (as 0) so a chip never disappears just because it is empty.
     *
     * @return array<string, int>
     */
    private function counts(int $userId): array
    {
        $tally = $this->orders->statusCounts($userId);

        $counts = ['all' => array_sum($tally)];
        foreach (StatusPresenter::REQUEST_STATUSES as $status) {
            $counts[$status] = $tally[$status] ?? 0;
        }

        return $counts;
    }
}
