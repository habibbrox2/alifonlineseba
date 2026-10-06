<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Service\PasswordResetService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET|POST /reset-password — prove the reset and set the new password.
 *
 * Two ways in, one form at the end:
 *
 *   ?token=…      the emailed link, on the GET. The form that link renders
 *                 posts back to /reset-password with the token in a hidden
 *                 field rather than in the action URL — a credential in a URL
 *                 is re-sent on every validation bounce and lands in access
 *                 logs — so a POST has to read it from the body.
 *   session       the OTP path, pinned by ForgotPasswordAction. The code is
 *                 posted, never put in a URL: a six-digit secret in a query
 *                 string lands in access logs, browser history and the
 *                 Referer of whatever the user opens next.
 *
 * A GET with neither simply sends the visitor back to /forgot-password rather
 * than rendering a form nobody can complete — a bookmarked /reset-password is
 * the common case, and a dead-end form is the worst answer to it.
 */
final readonly class ResetPasswordAction
{
    public function __construct(
        private WebViewRenderer $view,
        private PasswordResetService $resets,
        private ActivityLogRepository $logs,
        private UrlGeneratorInterface $url,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getAttribute('identity') instanceof Identity) {
            return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('profile')]);
        }

        $query = $request->getQueryParams();
        $token = trim((string) ($query['token'] ?? ''));

        // On a POST the query string is expected to be empty — see the form's
        // own note in reset-password.twig — so the token arrives in the body.
        // Ignoring it here meant the emailed link never worked at all: no
        // query token and no session-pinned OTP row is "nothing to verify",
        // which bounced a perfectly good link back to /forgot-password the
        // moment the visitor pressed save, without ever reading the secret.
        if ($token === '' && $request->getMethod() === 'POST') {
            $body = (array) $request->getParsedBody();
            $token = trim((string) ($body['token'] ?? ''));
        }

        $pending = $this->session->get('reset_pending');
        $pending = is_array($pending) ? $pending : null;

        $channel = $token !== '' ? 'email' : (string) ($pending['channel'] ?? '');
        $rowId = $token !== '' ? null : (isset($pending['rowId']) ? (int) $pending['rowId'] : null);

        if ($token === '' && $channel === '') {
            $this->session->set('flash_error', 'রিসেট লিংক বা কোড পাওয়া যায়নি। নতুন অনুরোধ করুন।');
            return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('forgot-password')]);
        }

        $errors = [];

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $secret = $channel === 'email'
                ? trim((string) ($input['token'] ?? ''))
                : trim((string) ($input['code'] ?? ''));
            $password = (string) ($input['password'] ?? '');
            $confirm = (string) ($input['password_confirm'] ?? '');

            if ($secret === '') {
                $errors['code'] = $channel === 'email' ? 'লিংক সঠিক নয়।' : 'কোড দিন।';
            } elseif ($password !== $confirm) {
                $errors['password_confirm'] = 'পাসওয়ার্ড মিলছে না।';
            } else {
                $result = $this->resets->consume(
                    $channel,
                    $secret,
                    $password,
                    (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
                    $rowId,
                );

                if ($result['ok']) {
                    $this->session->remove('reset_pending');
                    $this->logs->create([
                        'user_id' => $result['userId'],
                        'action' => 'auth.password_reset',
                        'description' => "Password reset completed via {$channel}",
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '-',
                        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
                    ]);
                    $this->session->set('flash_success', 'পাসওয়ার্ড পরিবর্তন হয়েছে। এখন লগইন করুন।');

                    return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('login')]);
                }

                $errors[$channel === 'email' ? 'token' : 'code'] = $result['error'];
            }
        }

        return $this->view->render('site/auth/reset-password.twig', [
            'channel' => $channel,
            'token' => $token,
            'errors' => $errors,
        ]);
    }
}
