<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\Identity;
use App\Repository\NotificationRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class NotificationsApiAction
{
    public function __construct(private NotificationRepository $notifications) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        // PATCH /api/notifications/{id}/read
        $id = $route->getArgument('id');
        if ($id !== null && $request->getMethod() === 'PATCH') {
            $this->notifications->markRead((int) $id, $identity->id);
            return Api::ok(null, 'Notification marked as read.');
        }

        $page = max(1, (int) ($request->getQueryParams()['page'] ?? '1'));
        $data = $this->notifications->forUser($identity->id, $page, 15);

        return Api::ok([
            'notifications' => $data['rows'],
            'total' => $data['total'],
            'unread' => $this->notifications->unreadCount($identity->id),
        ]);
    }
}
