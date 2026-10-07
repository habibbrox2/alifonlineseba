<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Auth\GoogleIdTokenVerifier;
use App\Auth\GoogleSignInService;
use App\Auth\IdentityRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * POST /auth/google — handle a Firebase `signInWithPopup` credential.
 *
 * The browser-side JS (resources/js/google-signin.js) gets an ID token from
 * Firebase and POSTs it here as `credential`. This action:
 *
 *  1. Verifies the token against this project's keys (RS256, signature, iss,
 *     aud, sub, expiry, provider, email verified, https picture).
 *  2. Provisions or looks up the account by verified email.
 *  3. Starts the session for the account (sets `user_id`, regenerates the ID
 *     for session-fixation protection — same as a password login).
 *  4. Sets a flash and redirects to the dashboard.
 *
 * When the verifier rejects the token the user is returned to the login page
 * with the rejection reason as a flash — never a 500, because a malformed or
 * expired token from a real user is a normal event, not a server fault.
 *
 * Google-provisioned accounts never have a password, so this action does not
 * route through IdentityRepository::login() (which verifies a password). It
 * sets the session directly the same way login() would after a successful
 * password check.
 */
final readonly class GoogleSignInAction
{
    public function __construct(
        private WebViewRenderer $view,
        private GoogleIdTokenVerifier $verifier,
        private GoogleSignInService $service,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = (array) $request->getParsedBody();
        $credential = (string) ($input['credential'] ?? '');

        if ($credential === '') {
            $this->session->set('flash_error', 'শংসাপত্র পাওয়া যায়নি। আবার চেষ্টা করুন।');
            return $this->back();
        }

        $result = $this->verifier->verify($credential);

        if ($result === null) {
            $this->session->set('flash_error', 'Google সাইন ইন যাচাই করা যায়নি। আবার চেষ্টা করুন।');
            return $this->back();
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

        $account = $this->service->provision($result, $ip, $ua);

        // Session-fixation protection, same as a password login.
        $this->session->regenerateID();
        $this->session->set(IdentityRepository::SESSION_KEY, $account['id']);

        $appUrl = (\App\Env::get('APP_URL') ?: 'http://localhost:8080');
        $redirect = rtrim((string) $appUrl, '/') . '/dashboard';

        if ($account['isNew']) {
            $this->session->set('flash_success', 'Google দিয়ে সাইন ইন সফল! অ্যাকাউন্ট তৈরি হয়েছে — স্বাগতম।');
        } else {
            $this->session->set('flash_success', 'লগইন সফল! স্বাগতম।');
        }

        return new \Nyholm\Psr7\Response(302, ['Location' => $redirect]);
    }

    /**
     * Return the user to the login page with any flash already set.
     *
     * We render the login view directly rather than redirect-to-self, because
     * a redirect would drop the flash if the session isn't persisted before the
     * 302 (and in CLI functional tests it often is not). Rendering keeps the
     * flash banner visible for the next screen the user sees.
     */
    private function back(): ResponseInterface
    {
        return $this->view->render('site/auth/login.twig', [
            'errors' => [],
            'message' => null,
            'old' => ['identifier' => ''],
        ]);
    }
}
