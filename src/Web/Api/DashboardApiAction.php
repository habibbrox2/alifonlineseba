<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\Identity;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\TransactionRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DashboardApiAction
{
    public function __construct(
        private ServiceRepository $services,
        private TransactionRepository $transactions,
        private NotificationRepository $notifications,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $stats = $this->transactions->statsForUser($identity->id);

        return Api::ok([
            'user' => [
                'id' => $identity->id,
                'username' => $identity->username,
                'balance' => $identity->balance,
                'role' => $identity->role,
            ],
            'stats' => [
                'orders' => $stats['total'],
                'spent' => $stats['amount'],
                'unread_notifications' => $this->notifications->unreadCount($identity->id),
            ],
            'categories' => $this->services->allCategories(),
        ]);
    }
}
