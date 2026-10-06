<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\ServiceOrderRepository;
use App\Repository\ServiceRepository;
use App\Repository\TopupRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminDashboardApiAction
{
    public function __construct(
        private UserRepository $users,
        private ServiceRepository $services,
        private ServiceOrderRepository $orders,
        private TransactionRepository $transactions,
        private ActivityLogRepository $logs,
        private TopupRepository $topups,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$identity->canAccessAdmin()) {
            return Api::forbidden();
        }

        $stats = $this->topups->stats();

        return Api::ok([
            'userCount' => $this->users->countAll(),
            'categoryCount' => count($this->services->allCategories(false)),
            'serviceCount' => count($this->services->servicesByCategory()),
            'txStats' => $this->transactions->statsAll(),
            'openOrders' => $this->orders->openOrders(),
            'recentLogs' => $this->logs->all(1, 8)['rows'],
            'topupStats' => $stats,
            'unresolvedCount' => $stats['unresolved'],
            'unresolvedAmount' => $stats['unresolved_amount'],
        ]);
    }
}
