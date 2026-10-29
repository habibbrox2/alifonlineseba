<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Auth\Identity;
use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Repository\UserRepository;
use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class LoginAction
{
    public function __construct(
        private WebViewRenderer $view,
        private AuthService $auth,
        private UrlGeneratorInterface $url,
        private SessionInterface $session,
        private UserRepository $users,
        private NotificationManager $notifications,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute('identity');
        if ($identity instanceof Identity) {
            return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('dashboard')]);
        }

        $errors = [];
        $message = null;
        $old = ['identifier' => ''];

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
            $result = $this->auth->login($input, $ip, $ua);

            if ($result['message'] === null && $result['errors'] === []) {
                $identifier = trim((string) ($input['identifier'] ?? ''));
                $user = $this->users->findByIdentifier($identifier);
                if ($user !== null && in_array((string) $user['role'], ['admin', 'superadmin', 'staff'], true)) {
                    try {
                        $this->notifications->dispatch(
                            NotificationEvent::ADMIN_LOGIN,
                            (int) $user['id'],
                            [
                                'username' => (string) $user['username'],
                                'role' => (string) $user['role'],
                                'device' => 'Web Browser',
                                'ip' => $ip,
                            ],
                            link: '/admin',
                        );
                    } catch (\Throwable) {}
                }

                $this->session->set('flash_success', 'লগইন সফল! স্বাগতম।');
                return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('dashboard')]);
            }

            $errors = $result['errors'];
            $message = $result['message'];
            $old['identifier'] = (string) ($input['identifier'] ?? '');
        }

        return $this->view->render('site/auth/login.twig', [
            'errors' => $errors,
            'message' => $message,
            'old' => $old,
        ]);
    }
}
