<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\ApiTokenRepository;
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
 *
 * This is Phase 1.5 — the gate everything mobile is blocked on. Credentials
 * and throttling are reused from the web login path, so the APK inherits
 * session-fixation handling and the brute-force throttle for free.
 */
final readonly class AuthApiAction
{
    public function __construct(
        private IdentityRepository $identities,
        private ApiTokenRepository $tokens,
        private ActivityLogRepository $logs,
        private UserRepository $users,
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

}
