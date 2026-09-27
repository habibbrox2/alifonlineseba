<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Repository\ActivityLogRepository;
use App\Repository\ServiceRepository;
use App\Repository\TopupRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class AdminDashboardAction
{
    public function __construct(
        private WebViewRenderer $view,
        private UserRepository $users,
        private ServiceRepository $services,
        private TransactionRepository $transactions,
        private ActivityLogRepository $logs,
        private TopupRepository $topups,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return $this->view->render('site/admin/index.twig', [
            'userCount' => $this->users->countAll(),
            'categoryCount' => count($this->services->allCategories(false)),
            'serviceCount' => count($this->services->servicesByCategory()),
            'txStats' => $this->transactions->statsAll(),
            'recentLogs' => $this->logs->all(1, 8)['rows'],
            'pendingCount' => $this->topups->pendingCount(),
        ]);
    }
}
