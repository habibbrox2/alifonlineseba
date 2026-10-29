<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\AdminWithdrawService;
use App\Service\Api;
use App\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminLedgerApiAction
{
    private const PER_PAGE = 20;

    public function __construct(
        private TransactionRepository $ledger,
        private UserRepository $users,
        private AdminWithdrawService $withdraws,
        private PermissionService $permissions,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$this->permissions->canAccessRoute($identity, 'admin-ledger')) {
            return Api::forbidden();
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $scope = (string) ($params['scope'] ?? 'me');
        $q = trim((string) ($params['q'] ?? ''));
        $adminId = (int) $identity->id;

        if ($scope === 'all' && !$identity->isSuperAdmin()) {
            return Api::forbidden();
        }

        if ($scope === 'all') {
            $data = $this->ledger->all($page, self::PER_PAGE, '', $q);
            return Api::ok([
                'rows' => $data['rows'],
                'total' => $data['total'],
                'page' => $page,
                'perPage' => self::PER_PAGE,
                'scope' => 'all',
                'users' => $this->users->listStaff(),
            ]);
        }

        $data = $this->ledger->forAdmin($adminId, $page, self::PER_PAGE);
        $fresh = $this->users->findById($adminId);
        $balance = (float) ($fresh['balance'] ?? 0.0);

        return Api::ok([
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'scope' => 'me',
            'balance' => $balance,
            'pending' => $this->withdraws->hasOpenRequest($adminId) ? 1 : 0,
            'minAmount' => $this->withdraws->minAmount(),
        ]);
    }
}
