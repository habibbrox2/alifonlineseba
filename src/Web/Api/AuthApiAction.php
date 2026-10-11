<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\ApiTokenRepository;
use App\Auth\FirebaseAuthService;
use App\Auth\FirebaseIdTokenVerifier;
use App\Auth\IdentityRepository;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * POST /api/auth/login     {identifier, password, device_label} → tokens + user
 * POST /api/auth/refresh   Authorization: Bearer <refresh>          → rotated pair
 * POST /api/auth/logout    revoke the presented token
 * POST /api/auth/firebase  {id_token, firebase_provider}           → tokens + user
 *
 * This is Phase 1.5 — the gate everything mobile is blocked on. Credentials
 * and throttling are reused from the web login path, so the APK inherits
 * session-fixation handling and the brute-force throttle for free.
 *
 * The firebase action mirrors the browser's POST /auth/firebase: it verifies
 * the ID token against Google's keys, provisions or matches an account by
 * verified email/phone, then issues the same API token pair a password login
 * would. It exists so an operator who should not hold a password can still use
 * the app — there is no password to leak because none was ever set.
 */
final readonly class AuthApiAction
{
    public function __construct(
        private IdentityRepository $identities,
        private ApiTokenRepository $tokens,
        private ActivityLogRepository $logs,
        private UserRepository $users,
        private FirebaseIdTokenVerifier $firebaseVerifier,
        private FirebaseAuthService $firebaseAccounts,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $method = $request->getMethod();

        // The route is GET|POST on /api/auth/{action}; PATCH-style refresh and
        // logout are POSTs so curl one-liners stay simple.
        $action = (string) $route->getArgument('action', '');

        if ($action === 'login' && $method === 'POST') {
            return $this->login($request);
        }
        if ($action === 'refresh' && $method === 'POST') {
            return $this->refresh($request);
        }
        if ($action === 'logout' && $method === 'POST') {
            return $this->logout($request);
        }
        if ($action === 'firebase' && $method === 'POST') {
            return $this->firebase($request);
        }

        return Api::fail('Unknown auth action.', [], 404);
    }

    private function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $identifier = trim((string) ($body['identifier'] ?? $body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $deviceLabel = trim((string) ($body['device_label'] ?? '')) ?: null;

        if ($identifier === '' || $password === '') {
            return Api::fail('identifier ও password দিন।', [], 422);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '-');
        $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

        $error = $this->identities->login($identifier, $password, $ip, $ua, remember: false);
        if ($error !== null) {
            return Api::fail('ভুল ইউজারনেম বা পাসওয়ার্ড।', [], 401);
        }

        // login() does not hand back the row; re-resolve by identifier for the
        // response payload (status was checked inside login()).
        $user = $this->users->findByIdentifier($identifier);
        if ($user === null) {
            return Api::fail('ভুল ইউজারনেম বা পাসওয়ার্ড।', [], 401);
        }

        $pair = $this->tokens->issue((int) $user['id'], $deviceLabel);

        $this->logs->create([
            'user_id' => (int) $user['id'],
            'action' => 'auth.api_token_issued',
            'description' => 'API token issued' . ($deviceLabel !== null ? ' for ' . $deviceLabel : ''),
            'ip_address' => $ip,
        ]);

        return Api::ok([
            'token' => $pair['token'],
            'refresh' => $pair['refresh'],
            'expires_at' => $pair['expires_at'],
            'user' => [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'phone' => (string) $user['phone'],
                'balance' => (float) $user['balance'],
                'role' => (string) $user['role'],
            ],
        ], 'লগইন সফল।');
    }

    private function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $bearer = \App\Auth\ApiAuthMiddleware::bearer($request);
        if ($bearer === null) {
            return Api::fail('Refresh token required.', [], 401);
        }

        $pair = $this->tokens->rotate($bearer);
        if ($pair === null) {
            return Api::fail('Invalid or expired refresh token.', [], 401);
        }

        return Api::ok($pair, 'Token rotated.');
    }

    private function logout(ServerRequestInterface $request): ResponseInterface
    {
        $bearer = \App\Auth\ApiAuthMiddleware::bearer($request);
        if ($bearer === null) {
            return Api::fail('Token required.', [], 401);
        }

        $revoked = $this->tokens->revoke($bearer);
        return Api::ok(['revoked' => $revoked], $revoked ? 'লগআউট হয়েছে।' : 'Token already invalid.');
    }

    /**
     * POST /api/auth/firebase — exchange a verified Firebase ID token for an
     * admin token pair.
     *
     * Same three moves as the browser's /auth/firebase, minus the session:
     * verify, provision-or-match, then issue. The only difference that matters
     * is the role gate — a Firebase sign-in can provision a brand new `user`,
     * and handing that account an admin token would be a privilege escalation
     * through a self-service signup.
     */
    private function firebase(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $idToken = trim((string) ($body['id_token'] ?? ''));
        $provider = trim((string) ($body['firebase_provider'] ?? '')) ?: 'unknown';
        $deviceLabel = trim((string) ($body['device_label'] ?? '')) ?: null;

        if ($idToken === '') {
            return Api::fail('id_token দিন।', [], 422);
        }

        // A token that fails verification is an ordinary event — a stale
        // device clock, a signed-out session, a mistyped number — so it is a
        // 401 with a readable message, never a 500.
        $claims = $this->firebaseVerifier->verify($idToken);
        if ($claims === null) {
            return Api::fail('ফায়ারবেজ শংসাপত্র যাচাই করা যায়নি। আবার চেষ্টা করুন।', [], 401);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '-');
        $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

        $account = $this->firebaseAccounts->provision($claims + [
            'firebase_provider' => $provider,
        ], $ip, $ua);

        $user = $this->users->findById((int) $account['id']);
        if ($user === null) {
            return Api::fail('অ্যাকাউন্ট পাওয়া যায়নি।', [], 401);
        }

        // Provisioned accounts land on role `user`. An operator who has not
        // been promoted cannot reach a single admin endpoint, so say so rather
        // than handing back a token that 403s on every call.
        $role = (string) $user['role'];
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return Api::fail('এই অ্যাকাউন্টের অ্যাডমিন প্রবেশাধিকার নেই।', [], 403);
        }

        $pair = $this->tokens->issue((int) $account['id'], $deviceLabel);

        $this->logs->create([
            'user_id' => (int) $account['id'],
            'action' => 'auth.api_token_issued',
            'description' => 'API token issued via Firebase (' . $provider . ')'
                . ($deviceLabel !== null ? ' for ' . $deviceLabel : ''),
            'ip_address' => $ip,
        ]);

        return Api::ok([
            'token' => $pair['token'],
            'refresh' => $pair['refresh'],
            'expires_at' => $pair['expires_at'],
            'user' => [
                'id' => (int) $account['id'],
                'username' => (string) $account['username'],
                'phone' => (string) $user['phone'],
                'balance' => (float) ($user['balance'] ?? 0),
                'role' => $role,
            ],
        ], $account['isNew'] ? 'ফায়ারবেজ দিয়ে সাইন ইন সফল — অ্যাকাউন্ট তৈরি হয়েছে।' : 'লগইন সফল।');
    }

}
