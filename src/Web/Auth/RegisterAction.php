<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Auth\Identity;
use App\Service\Api;
use App\Service\AuthService;
use App\Service\ReferralCode;
use App\Service\ReferralService;
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
        private ReferralService $referrals,
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

        // The referral code travels in the URL on the way in and in a hidden
        // field on every re-render, so a validation error (which bounces back
        // to a bare POST) does not quietly drop the attribution the user
        // arrived with.
        $refCode = ReferralCode::normalise(
            (string) ($request->getQueryParams()['ref'] ?? '')
        );
        if ($refCode !== '' && !ReferralCode::isValid($refCode)) {
            // Not a code at all (a stray ?ref= from another site). Drop it
            // rather than showing the user a field that will never work.
            $refCode = '';
        }
        $refCodeValid = $refCode !== '' && $this->referrals->resolveCode($refCode) !== null;

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

            // The hidden field wins: on a re-render it holds exactly what the
            // user was shown on the GET.
            $posted = ReferralCode::normalise((string) ($input['referral_code'] ?? ''));
            if (ReferralCode::isValid($posted)) {
                $refCode = $posted;
                $refCodeValid = $this->referrals->resolveCode($refCode) !== null;
            }
            $input['referral_code'] = $refCode;

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
            'refCode' => $refCode,
            'refCodeValid' => $refCodeValid,
            'referrerBonus' => $this->referrals->referrerBonus(),
        ]);
    }
}
