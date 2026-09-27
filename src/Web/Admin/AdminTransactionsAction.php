<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Repository\TransactionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class AdminTransactionsAction
{
    public function __construct(
        private WebViewRenderer $view,
        private TransactionRepository $transactions,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $status = (string) ($params['status'] ?? '');
        $q = trim((string) ($params['q'] ?? ''));
        $sort = (string) ($params['sort'] ?? 'id');
        $dir = (string) ($params['dir'] ?? 'desc');
        $perPage = 20;

        $data = $this->transactions->all($page, $perPage, $status, $q, $sort, $dir);

        return $this->view->render('site/admin/transactions.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $perPage,
            'status' => $status,
            'q' => $q,
            'sort' => isset(TransactionRepository::SORTABLE[$sort]) ? $sort : 'id',
            'dir' => strtolower($dir) === 'asc' ? 'asc' : 'desc',
        ]);
    }
}
