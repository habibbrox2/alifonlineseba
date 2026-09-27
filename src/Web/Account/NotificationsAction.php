<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Auth\Identity;
use App\Repository\NotificationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class NotificationsAction
{
    public function __construct(
        private WebViewRenderer $view,
        private NotificationRepository $notifications,
        private UrlGeneratorInterface $url,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        // POST /notifications/read-all
        if ($request->getMethod() === 'POST' && $route->getArgument('action') === 'read-all') {
            $this->notifications->markAllRead($identity->id);
            $this->session->set('flash_success', 'সব নোটিফিকেশন পড়া হয়েছে।');
            return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('notifications')]);
        }

        $page = max(1, (int) ($request->getQueryParams()['page'] ?? '1'));
        $perPage = 15;
        $data = $this->notifications->forUser($identity->id, $page, $perPage);

        return $this->view->render('site/account/notifications.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $perPage,
            'identity' => $identity,
        ]);
    }
}
