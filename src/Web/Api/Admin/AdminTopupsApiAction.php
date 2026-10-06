<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\TopupRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminTopupsApiAction
{
    private const PER_PAGE = 20;

    public function __construct(
        private TopupRepository $topups,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$identity->canAccessAdmin()) {
            return Api::forbidden();
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $status = (string) ($params['status'] ?? '');

        $data = $this->topups->adminList($page, self::PER_PAGE, $status);

        return Api::ok([
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'status' => $status,
        ]);
    }
}
