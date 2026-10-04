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

        // `type` filters by ledger entry type, not by order status: this is a
        // statement of what happened to the balance. An unrecognised value is
        // ignored rather than 422'd, because the app ships a new client and an
        // old server far more often than the other way round.
        $requested = (string) ($params['type'] ?? '');
        $type = in_array($requested, TransactionRepository::TYPES, true) ? $requested : '';

        $data = $this->transactions->forUser($identity->id, $page, 15, $type);

        return Api::ok([
            'transactions' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'balance' => $identity->balance,
        ]);
    }
}
