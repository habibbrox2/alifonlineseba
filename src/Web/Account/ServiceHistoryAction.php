<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Auth\Identity;
use App\Repository\ServiceRepository;
use App\Repository\TransactionRepository;
use App\Service\RequestRowPresenter;
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
        private TransactionRepository $transactions,
        private ServiceRepository $services,
        private RequestRowPresenter $presenter,
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
            $data = $this->transactions->forUserByCategory($identity->id, $page, self::PER_PAGE, $category);
            // An unknown slug returns 0 rows; that is the correct empty state,
            // same as a status filter that matches nothing.
        } else {
            $data = $this->transactions->forUser($identity->id, $page, self::PER_PAGE, $status);
        }

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
            'categoryCounts' => $this->transactions->categoryCounts($identity->id),
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
     * Row count per status, for the filter chips. Every known status is present
     * (as 0) so a chip never disappears just because it is empty.
     *
     * @return array<string, int>
     */
    private function counts(int $userId): array
    {
        $tally = $this->transactions->statusCounts($userId);

        $counts = ['all' => array_sum($tally)];
        foreach (StatusPresenter::REQUEST_STATUSES as $status) {
            $counts[$status] = $tally[$status] ?? 0;
        }

        return $counts;
    }
}
