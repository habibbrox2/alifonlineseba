<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Auth\Identity;
use App\Repository\TransactionRepository;
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

        $data = $this->transactions->forUser($identity->id, $page, self::PER_PAGE, $status);

        return $this->view->render('site/account/service-history.twig', [
            'requests' => $this->decorate($data['rows']),
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'status' => $status,
            'counts' => $this->counts($identity->id),
            'identity' => $identity,
        ]);
    }

    /**
     * Attach the presentation data each row needs so the template stays dumb and
     * the JSON API can reuse the exact same payload after an AJAX status change.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function decorate(array $rows): array
    {
        $decorated = [];
        foreach ($rows as $row) {
            $metadata = TransactionRepository::metadata($row);
            $status = (string) $row['status'];

            $decorated[] = [
                'id' => (int) $row['id'],
                'reference' => (string) $row['reference'],
                'service_name' => (string) ($row['service_name'] ?? ($metadata['service_name'] ?? 'সার্ভিস')),
                'amount' => (float) $row['amount'],
                'status' => $status,
                'status_label' => StatusPresenter::label($status),
                'status_badge' => StatusPresenter::badge($status),
                'actions' => StatusPresenter::requestActions($status),
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
                'result' => is_array($metadata['result'] ?? null) ? $metadata['result'] : null,
                'result_entries' => StatusPresenter::resultEntries(
                    is_array($metadata['result'] ?? null) ? $metadata['result'] : null
                ),
                'error' => isset($metadata['error']) ? (string) $metadata['error'] : null,
            ];
        }

        return $decorated;
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
