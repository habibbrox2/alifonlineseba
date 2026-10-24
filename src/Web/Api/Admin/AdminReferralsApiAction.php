<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\ReferralRepository;
use App\Service\Api;
use App\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminReferralsApiAction
{
    public function __construct(
        private ReferralRepository $referrals,
        private PermissionService $permissions,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$this->permissions->canAccessRoute($identity, 'admin-referrals')) {
            return Api::forbidden();
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $data = $this->referrals->paginateOverview($page, 20);
        return Api::ok([
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => 20,
        ]);
    }
}
