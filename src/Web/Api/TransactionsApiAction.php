<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\Identity;
use App\Repository\TransactionRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class TransactionsApiAction
{
    public function __construct(private TransactionRepository $transactions) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $status = (string) ($params['status'] ?? '');

        $data = $this->transactions->forUser($identity->id, $page, 15, $status);

        return Api::ok([
            'transactions' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
        ]);
    }
}
