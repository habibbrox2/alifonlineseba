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

        // POST /api/notifications/read-all — the header dropdown has no page to
        // redirect to, so it needs the same operation as JSON. markAllRead()
        // only touches unread rows, so repeating it is harmless.
        if ($request->getMethod() === 'POST') {
            $this->notifications->markAllRead($identity->id);
            return Api::ok(['unread' => 0], 'সব নোটিফিকেশন পড়া হয়েছে।');
        }

        // PATCH /api/notifications/{id}/read
        $id = $route->getArgument('id');
        if ($id !== null && $request->getMethod() === 'PATCH') {
            $this->notifications->markRead((int) $id, $identity->id);
            return Api::ok([
                'id' => (int) $id,
                'unread' => $this->notifications->unreadCount($identity->id),
            ], 'Notification marked as read.');
        }

        $page = max(1, (int) ($request->getQueryParams()['page'] ?? '1'));
        $data = $this->notifications->forUser($identity->id, $page, 15);

        return Api::ok([
            'notifications' => $data['rows'],
            'total' => $data['total'],
            'unread' => $this->notifications->unreadCount($identity->id),
        ], $data['rows'] === [] ? 'কোনো নোটিফিকেশন নেই।' : '');
    }
}
