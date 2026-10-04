<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Repository\ActivityLogRepository;
use App\Repository\ServiceOrderRepository;
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
        private ServiceOrderRepository $orders,
        private TransactionRepository $transactions,
        private ActivityLogRepository $logs,
        private TopupRepository $topups,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $stats = $this->topups->stats();

        return $this->view->render('site/admin/index.twig', [
            'userCount' => $this->users->countAll(),
            'categoryCount' => count($this->services->allCategories(false)),
            'serviceCount' => count($this->services->servicesByCategory()),
            'txStats' => $this->transactions->statsAll(),
            'openOrders' => $this->orders->openOrders(),
            'recentLogs' => $this->logs->all(1, 8)['rows'],
            'topupStats' => $stats,
            // What still needs a human: claimed (`review`) and unclaimed
            // (`pending`) requests together. Only `pending` would under-report a
            // queue that is actively being worked and look like it is empty.
            'unresolvedCount' => $stats['unresolved'],
            'unresolvedAmount' => $stats['unresolved_amount'],
        ]);
    }
}
