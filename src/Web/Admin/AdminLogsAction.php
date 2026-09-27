<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Repository\ActivityLogRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class AdminLogsAction
{
    public function __construct(
        private WebViewRenderer $view,
        private ActivityLogRepository $logs,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $q = trim((string) ($params['q'] ?? ''));
        $perPage = 25;

        $data = $this->logs->all($page, $perPage, $q);

        return $this->view->render('site/admin/logs.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $perPage,
            'q' => $q,
        ]);
    }
}
