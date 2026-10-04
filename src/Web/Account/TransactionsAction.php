<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Auth\Identity;
use App\Repository\TransactionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class TransactionsAction
{
    public function __construct(
        private WebViewRenderer $view,
        private TransactionRepository $transactions,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $page = max(1, (int) ($route->getArgument('page', '1')));

        // The filter is a ledger `type`, not an order status: this page is a
        // statement of what happened to the balance, and "completed" is not a
        // thing that happened to money. An unrecognised type falls back to "all"
        // rather than to an empty list, so a stale link shows the statement
        // instead of claiming there is nothing in it.
        $requested = (string) ($request->getQueryParams()['type'] ?? '');
        $type = in_array($requested, TransactionRepository::TYPES, true) ? $requested : '';

        $perPage = 15;
        $data = $this->transactions->forUser($identity->id, $page, $perPage, $type);

        return $this->view->render('site/account/transactions.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $perPage,
            'type' => $type,
            'types' => TransactionRepository::TYPES,
            'identity' => $identity,
        ]);
    }
}
