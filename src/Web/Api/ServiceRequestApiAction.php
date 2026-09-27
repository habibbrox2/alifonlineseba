<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\Identity;
use App\Repository\TransactionRepository;
use App\Service\Api;
use App\Service\ServiceManager;
use App\Service\StatusPresenter;
use App\ServiceProvider\ServiceResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * POST /api/service-requests/{id}/{action} — the status changes the history
 * page performs over AJAX. The response carries the whole re-rendered row, so
 * the client never has to guess the new label or the next set of buttons.
 */
final readonly class ServiceRequestApiAction
{
    /** Only these verbs reach the ServiceManager; 'view' is answered client-side. */
    private const REMOTE_ACTIONS = ['start', 'cancel', 'retry'];

    public function __construct(
        private ServiceManager $manager,
        private TransactionRepository $transactions,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        $id = (int) $route->getArgument('id', '0');
        $action = (string) $route->getArgument('action', '');

        if (!in_array($action, self::REMOTE_ACTIONS, true)) {
            return Api::fail('Unknown action.', [], 404);
        }

        $request_row = $this->transactions->findOwned($id, $identity->id);
        if ($request_row === null) {
            // Also covers someone else's request — never leak that it exists.
            return Api::fail('অনুরোধটি পাওয়া যায়নি।', [], 404);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '-');
        $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

        $result = match ($action) {
            'start' => $this->manager->start($request_row, $identity, $ip, $userAgent),
            'cancel' => $this->manager->cancel($request_row, $identity, $ip, $userAgent),
            'retry' => $this->manager->retry($request_row, $identity, $ip, $userAgent),
        };

        $fresh = $this->transactions->findById($id);
        $row = $fresh === null ? $request_row : $fresh;

        if (!$result->success) {
            return Api::fail($result->message, $result->errors, 422);
        }

        return Api::ok(
            $this->present($row, $result),
            $result->message,
        );
    }

    /**
     * The row as the client needs it: new status, its Bengali label, the badge
     * class, the buttons valid from here on, plus the result when it completed.
     *
     * @return array<string, mixed>
     */
    private function present(array $row, ServiceResult $result): array
    {
        $metadata = TransactionRepository::metadata($row);
        $status = (string) $row['status'];

        return [
            'id' => (int) $row['id'],
            'reference' => (string) $row['reference'],
            'status' => $status,
            'status_label' => StatusPresenter::label($status),
            'status_badge' => StatusPresenter::badge($status),
            'actions' => StatusPresenter::requestActions($status),
            'updated_at' => (string) $row['updated_at'],
            'result' => is_array($metadata['result'] ?? null) ? $metadata['result'] : null,
            'result_entries' => StatusPresenter::resultEntries(
                is_array($metadata['result'] ?? null) ? $metadata['result'] : null
            ),
            'error' => isset($metadata['error']) ? (string) $metadata['error'] : null,
            'provider_data' => $result->success ? array_filter(
                $result->data,
                static fn ($value, $key): bool => !str_starts_with((string) $key, '_'),
                ARRAY_FILTER_USE_BOTH,
            ) : [],
        ];
    }
}
