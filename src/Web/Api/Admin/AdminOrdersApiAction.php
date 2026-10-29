<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\ServiceOrderRepository;
use App\Repository\UserRepository;
use App\Service\Api;
use App\Service\BulkJobService;
use App\Service\ServiceRequestAdminService;
use App\Service\StatusPresenter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class AdminOrdersApiAction
{
    private const PER_PAGE = 20;

    public const SCOPE_ALL = 'all';
    public const SCOPE_MINE = 'mine';
    public const SCOPE_TAKEN = 'taken';

    public function __construct(
        private ServiceOrderRepository $orders,
        private BulkJobService $bulk,
        private ServiceRequestAdminService $service,
        private UserRepository $users,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$identity->canAccessAdmin()) {
            return Api::forbidden();
        }

        $id = (int) $route->getArgument('id', '0');

        if ($request->getMethod() === 'POST') {
            return $this->handlePost((array) $request->getParsedBody(), $id, $identity);
        }

        if ($id > 0) {
            $row = $this->orders->findById($id);
            if ($row === null) {
                return Api::fail('অর্ডার পাওয়া যায়নি।', [], 404);
            }

            $metadata = ServiceOrderRepository::metadata($row);
            $claimedBy = $row['claimed_by'] === null ? null : (int) $row['claimed_by'];
            $user = $this->users->findById((int) $row['user_id']);
            $claimer = $claimedBy === null ? null : $this->users->findById($claimedBy);
            $approver = $row['approved_by'] === null ? null : $this->users->findById((int) $row['approved_by']);

            return Api::ok([
                'order' => $row,
                'user' => $user ? [
                    'id' => (int) $user['id'],
                    'name' => (string) ($user['full_name'] ?? $user['name'] ?? $user['username'] ?? ''),
                    'phone' => (string) ($user['phone'] ?? ''),
                    'email' => (string) ($user['email'] ?? ''),
                ] : null,
                'metadata' => $metadata,
                'canDecide' => $claimedBy === null || $claimedBy === $identity->id,
                'isMine' => $claimedBy === $identity->id,
                'claimer' => $claimer ? [
                    'id' => (int) $claimer['id'],
                    'name' => (string) ($claimer['full_name'] ?? $claimer['name'] ?? $claimer['username'] ?? ''),
                ] : null,
                'approver' => $approver ? [
                    'id' => (int) $approver['id'],
                    'name' => (string) ($approver['full_name'] ?? $approver['name'] ?? $approver['username'] ?? ''),
                ] : null,
                'resultEntries' => StatusPresenter::resultEntries(
                    is_array($metadata['result'] ?? null) ? $metadata['result'] : null,
                ),
            ]);
        }

        $state = $this->state($request->getQueryParams());

        $data = $this->orders->adminList(
            $state['page'],
            self::PER_PAGE,
            $state['status'],
            $state['q'],
            $state['sort'],
            $state['dir'],
            $state['scope'] === self::SCOPE_MINE ? $identity->id : null,
            $state['scope'] === self::SCOPE_ALL,
        );

        return Api::ok([
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $state['page'],
            'perPage' => self::PER_PAGE,
            'status' => $state['status'],
            'q' => $state['q'],
            'scope' => $state['scope'],
            'sort' => $state['sort'],
            'dir' => $state['dir'],
            'mine' => $this->orders->claimedBy($identity->id),
            'open' => $this->orders->openOrders(),
        ]);
    }

    private function handlePost(array $input, int $routeId, Identity $identity): ResponseInterface
    {
        $do = (string) ($input['do'] ?? '');
        $id = $routeId > 0 ? $routeId : (int) ($input['id'] ?? 0);

        if ($id > 0) {
            $row = $this->orders->findById($id);
            if ($row === null) {
                return Api::fail('অর্ডার পাওয়া যায়নি।', [], 404);
            }

            $note = trim((string) ($input['admin_note'] ?? ''));

            [$ok, $message] = match ($do) {
                'claim' => $this->service->claim($id, $identity),
                'release' => $this->service->release($id, $identity),
                'approve' => $this->service->approve($id, $identity, $note),
                'reject' => $this->service->refund(
                    $id,
                    $identity,
                    (string) ($input['status'] ?? StatusPresenter::CANCELLED),
                    trim((string) ($input['reason'] ?? '')),
                    $note,
                ),
                'status' => $this->service->setStatus($id, (string) ($input['status'] ?? ''), $identity, $note),
                default => [false, 'অজানা অ্যাকশন।'],
            };

            return $ok ? Api::ok(['message' => $message], $message) : Api::fail($message);
        }

        if ($do === 'bulk_status') {
            $result = $this->bulk->enqueueSettle(
                (array) ($input['ids'] ?? []),
                (string) ($input['status'] ?? ''),
                $identity,
            );

            return $result['ok']
                ? Api::ok(['message' => $result['message']], $result['message'])
                : Api::fail($result['message']);
        }

        return Api::fail('অজানা অ্যাকশন।');
    }

    private function state(array $params): array
    {
        $sort = (string) ($params['sort'] ?? 'id');
        $dir = strtolower((string) ($params['dir'] ?? 'desc'));
        $scope = (string) ($params['scope'] ?? self::SCOPE_ALL);

        return [
            'page' => max(1, (int) ($params['page'] ?? '1')),
            'status' => (string) ($params['status'] ?? ''),
            'q' => trim((string) ($params['q'] ?? '')),
            'scope' => in_array($scope, [self::SCOPE_ALL, self::SCOPE_MINE, self::SCOPE_TAKEN], true)
                ? $scope
                : self::SCOPE_ALL,
            'sort' => in_array($sort, ServiceOrderRepository::SORTABLE, true) ? $sort : 'id',
            'dir' => $dir === 'asc' ? 'asc' : 'desc',
        ];
    }
}
