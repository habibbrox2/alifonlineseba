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
        $status = (string) ($request->getQueryParams()['status'] ?? '');
        $perPage = 15;

        $data = $this->transactions->forUser($identity->id, $page, $perPage, $status);

        return $this->view->render('site/account/transactions.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $perPage,
            'status' => $status,
            'identity' => $identity,
        ]);
    }
}
