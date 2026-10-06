<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\UserRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminStaffApiAction
{
    private const PER_PAGE = 20;

    public function __construct(
        private UserRepository $users,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$identity->isSuperAdmin()) {
            return Api::forbidden();
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $q = trim((string) ($params['q'] ?? ''));

        $data = $this->users->paginateStaff($page, self::PER_PAGE, $q);

        return Api::ok([
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'q' => $q,
        ]);
    }
}
