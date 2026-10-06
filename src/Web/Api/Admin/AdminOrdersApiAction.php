<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\ServiceOrderRepository;
use App\Service\Api;
use App\Service\BulkJobService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminOrdersApiAction
{
    private const PER_PAGE = 20;

    public const SCOPE_ALL = 'all';
    public const SCOPE_MINE = 'mine';
    public const SCOPE_TAKEN = 'taken';

    public function __construct(
        private ServiceOrderRepository $orders,
        private BulkJobService $bulk,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$identity->canAccessAdmin()) {
            return Api::forbidden();
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost((array) $request->getParsedBody(), $identity);
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

    private function handlePost(array $input, Identity $identity): ResponseInterface
    {
        $do = (string) ($input['do'] ?? '');

        if ($do === 'bulk_status') {
            $result = $this->bulk->enqueueSettle(
                (array) ($input['ids'] ?? []),
                (string) ($input['status'] ?? ''),
                $identity,
            );

            return $result['ok']
                ? Api::ok(['message' => $result['message']])
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
            'sort' => isset(ServiceOrderRepository::SORTABLE[$sort]) ? $sort : 'id',
            'dir' => $dir === 'asc' ? 'asc' : 'desc',
        ];
    }
}
