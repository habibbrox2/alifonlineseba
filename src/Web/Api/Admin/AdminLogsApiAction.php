<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminLogsApiAction
{
    private const PER_PAGE = 25;

    public function __construct(
        private ActivityLogRepository $logs,
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
        $q = trim((string) ($params['q'] ?? ''));

        $data = $this->logs->all($page, self::PER_PAGE, $q);

        return Api::ok([
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'q' => $q,
        ]);
    }
}
