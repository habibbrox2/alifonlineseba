<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Auth\Identity;
use App\Service\Api;
use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class RegisterAction
{
    public function __construct(
        private WebViewRenderer $view,
        private AuthService $auth,
        private UrlGeneratorInterface $url,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute('identity');
        if ($identity instanceof Identity) {
            return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('dashboard')]);
        }

        $errors = [];
        $old = [];

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
            $result = $this->auth->register($input, $ip, $ua);

            if ($result['errors'] === []) {
                $this->session->set('flash_success', 'নিবন্ধন সফল! এখন লগইন করুন।');
                return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('login')]);
            }

            $errors = $result['errors'];
            $old = [
                'username' => (string) ($input['username'] ?? ''),
                'phone' => (string) ($input['phone'] ?? ''),
                'email' => (string) ($input['email'] ?? ''),
            ];
        }

        return $this->view->render('site/auth/register.twig', [
            'errors' => $errors,
            'old' => $old,
        ]);
    }
}
