<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\AdminWithdrawRepository;
use App\Service\AdminWithdrawService;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminWithdrawsApiAction
{
    private const PER_PAGE = 20;

    public function __construct(
        private AdminWithdrawRepository $withdraws,
        private AdminWithdrawService $service,
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
        $status = (string) ($params['status'] ?? '');
        $q = trim((string) ($params['q'] ?? ''));

        $data = $this->withdraws->adminList($page, self::PER_PAGE, $status, $q);

        return Api::ok([
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'status' => $status,
            'q' => $q,
            'stats' => $this->withdraws->stats(),
        ]);
    }
}
